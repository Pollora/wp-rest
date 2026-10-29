<?php

declare(strict_types=1);

namespace Pollora\WpRest\Infrastructure\Services;

use Pollora\Attributes\WpRestRoute\Method;
use Pollora\Attributes\WpRestRoute\Permission;
use Pollora\Support\WpGlobals;
use Pollora\WpRest\Permissions\Everyone;
use ReflectionMethod;
use WP_Error;
use WP_REST_Request;

/**
 * Registers the REST routes declared by #[Method] attributes.
 *
 * Calls the decorated method with the request or the route parameters it
 * names, and checks the method's permission, or else the route's.
 */
final class WpRestRouteRegistrar
{
    /**
     * Filter that decides whether a route declaring no permission is refused.
     * It is, by default; return false to keep such routes public while a site
     * migrates, with the notice that tells which route to fix.
     */
    public const string PRIVATE_BY_DEFAULT_FILTER = 'pollora/wp-rest/private_by_default';

    /**
     * Register the REST route a #[Method] declares.
     *
     * @param  WpRestAttributableWrapper  $instance  The route class, wrapped with its namespace, route and permission
     * @param  ReflectionMethod  $context  The decorated method
     * @param  Method  $attribute  The attribute instance
     */
    public function register(WpRestAttributableWrapper $instance, ReflectionMethod $context, Method $attribute): void
    {
        $methodPermission = $attribute->permissionCallback;
        $permissionCallback = $methodPermission ?? $instance->classPermission;

        register_rest_route(
            $instance->namespace,
            $instance->route,
            [
                'methods' => $attribute->getMethods(),
                'callback' => WpGlobals::wrap(
                    fn (WP_REST_Request $request): mixed => $this->handleRequest($instance, $context, $request)
                ),
                'args' => $this->extractArgsFromRoute($instance->route),
                'permission_callback' => $permissionCallback === null
                    ? $this->withoutPermission($instance)
                    : $this->resolvePermissionCallback($permissionCallback),
            ]
        );
    }

    /**
     * Executes the class method associated with the REST request.
     *
     * @param  WpRestAttributableWrapper  $instance  The route class wrapper
     * @param  ReflectionMethod  $method  The method to invoke
     * @param  WP_REST_Request  $request  The REST request instance
     * @return mixed The result of the method invocation
     */
    private function handleRequest(WpRestAttributableWrapper $instance, ReflectionMethod $method, WP_REST_Request $request): mixed
    {
        $args = [];

        foreach ($method->getParameters() as $param) {
            // A WP_REST_Request type hint gets the request itself. Looking its
            // name up among the route parameters found nothing — no route
            // declares one called "request" — so the handler was invoked with
            // null and a controller declaring the type fataled before running.
            if ($this->expectsTheRequest($param)) {
                $args[] = $request;

                continue;
            }

            $args[] = $request->get_param($param->getName());
        }

        return $method->invoke($instance->getRealInstance(), ...$args);
    }

    /**
     * Whether a parameter asks for the request object rather than a route value.
     */
    private function expectsTheRequest(\ReflectionParameter $param): bool
    {
        $type = $param->getType();

        if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return false;
        }

        $name = $type->getName();

        // The name comparison stands on its own: ::class resolves whether or
        // not WordPress is loaded, so this holds outside a WordPress runtime.
        return $name === WP_REST_Request::class
            || is_a($name, WP_REST_Request::class, true);
    }

    /**
     * Extracts dynamic arguments from a WordPress route.
     *
     * @param  string  $route  The route pattern
     * @return array The extracted arguments
     */
    private function extractArgsFromRoute(string $route): array
    {
        preg_match_all('/\(\?P<(\w+)>/', $route, $matches);

        return array_fill_keys($matches[1], [
            'validate_callback' => fn ($param): bool => is_string($param) || is_numeric($param),
        ]);
    }

    /**
     * The permission of a route that declares none.
     *
     * Nothing in the code tells a deliberate public endpoint from a forgotten
     * check, so such a route is refused: Everyone::class makes a public route
     * explicit. A site that is migrating can return false from the
     * private_by_default filter to keep these routes public; WordPress is then
     * told which route to fix (shown with WP_DEBUG).
     *
     * @param  WpRestAttributableWrapper  $instance  The route class wrapper
     * @return callable The permission function
     */
    private function withoutPermission(WpRestAttributableWrapper $instance): callable
    {
        $route = trim($instance->namespace, '/').'/'.ltrim($instance->route, '/');

        if (apply_filters(self::PRIVATE_BY_DEFAULT_FILTER, true) !== false) {
            return static fn (): WP_Error => new WP_Error(
                'rest_forbidden',
                __('This endpoint declares no permission.'),
                ['status' => rest_authorization_required_code()]
            );
        }

        // A notice printed while answering a REST request would corrupt its JSON
        if (wp_is_serving_rest_request()) {
            return '__return_true';
        }

        _doing_it_wrong(
            self::class.'::register',
            sprintf(
                'The REST route %s declares no permission, so anyone can call it. Pass permissionCallback: %s::class to keep it public on purpose, or a permission such as IsLoggedIn. Without the pollora/wp-rest/private_by_default filter returning false, this route is refused.',
                $route,
                Everyone::class,
            ),
            '2.0.0'
        );

        return '__return_true';
    }

    /**
     * Resolves and executes the permission callback.
     *
     * @param  class-string<Permission>|Permission  $permissionCallback  The permission class or instance to use
     * @return callable The permission function
     */
    private function resolvePermissionCallback(string|Permission $permissionCallback): callable
    {
        if (is_string($permissionCallback) && (! class_exists($permissionCallback) || ! is_subclass_of($permissionCallback, Permission::class))) {
            return fn (): WP_Error => new WP_Error('rest_forbidden', __('Invalid permission handler.'), ['status' => 403]);
        }

        return WpGlobals::wrap(function (WP_REST_Request $request) use ($permissionCallback): bool|WP_Error {
            $permissionInstance = is_string($permissionCallback) ? new $permissionCallback : $permissionCallback;

            return $permissionInstance->allow($request);
        });
    }
}
