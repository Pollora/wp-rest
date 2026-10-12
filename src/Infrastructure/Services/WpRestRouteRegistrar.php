<?php

declare(strict_types=1);

namespace Pollora\WpRest\Infrastructure\Services;

use Pollora\Attributes\WpRestRoute\Method;
use Pollora\Attributes\WpRestRoute\Permission;
use Pollora\Support\WpGlobals;
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
                'permission_callback' => $this->resolvePermissionCallback($permissionCallback),
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
     * Resolves and executes the permission callback.
     *
     * @param  class-string<Permission>|Permission|null  $permissionCallback  The permission class or instance to use
     * @return callable The permission function
     */
    private function resolvePermissionCallback(string|Permission|null $permissionCallback): callable
    {
        if ($permissionCallback === null) {
            return '__return_true';
        }

        if (is_string($permissionCallback) && (! class_exists($permissionCallback) || ! is_subclass_of($permissionCallback, Permission::class))) {
            return fn (): WP_Error => new WP_Error('rest_forbidden', __('Invalid permission handler.'), ['status' => 403]);
        }

        return WpGlobals::wrap(function (WP_REST_Request $request) use ($permissionCallback): bool|WP_Error {
            $permissionInstance = is_string($permissionCallback) ? new $permissionCallback : $permissionCallback;

            return $permissionInstance->allow($request);
        });
    }
}
