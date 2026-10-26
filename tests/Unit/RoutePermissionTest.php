<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\Attributes\WpRestRoute\Method;
use Pollora\WpRest\Infrastructure\Services\WpRestAttributableWrapper;
use Pollora\WpRest\Infrastructure\Services\WpRestRouteRegistrar;
use Pollora\WpRest\Permissions\Everyone;
use Pollora\WpRest\Permissions\IsLoggedIn;

final class StatusController
{
    public function show(): array
    {
        return ['status' => 'ok'];
    }
}

/**
 * Registers the route and returns the permission callback WordPress was given.
 */
function permissionOf(string|IsLoggedIn|Everyone|null $permission): callable
{
    $registered = null;
    Functions\when('register_rest_route')->alias(function (string $namespace, string $route, array $args) use (&$registered): bool {
        $registered = $args['permission_callback'];

        return true;
    });

    (new WpRestRouteRegistrar)->register(
        new WpRestAttributableWrapper(new StatusController, 'app/v1', '/status', $permission),
        new ReflectionMethod(StatusController::class, 'show'),
        new Method('GET'),
    );

    return $registered;
}

beforeEach(function (): void {
    Functions\when('rest_authorization_required_code')->justReturn(401);

    $this->notices = [];
    Functions\when('_doing_it_wrong')->alias(function (string $function, string $message, string $version): void {
        $this->notices[] = [$function, $message, $version];
    });
});

it('keeps a route without a permission public, and tells WordPress it is doing it wrong', function (): void {
    expect(permissionOf(null)(Mockery::mock(WP_REST_Request::class)))->toBeTrue()
        ->and($this->notices)->toHaveCount(1)
        ->and($this->notices[0][0])->toBe(WpRestRouteRegistrar::class.'::register')
        ->and($this->notices[0][1])->toContain('app/v1/status', Everyone::class)
        ->and($this->notices[0][2])->toBe('0.2.0');
});

it('keeps the notice out of a REST response, where it would corrupt the JSON', function (): void {
    Functions\when('wp_is_serving_rest_request')->justReturn(true);

    expect(permissionOf(null)(Mockery::mock(WP_REST_Request::class)))->toBeTrue()
        ->and($this->notices)->toBe([]);
});

it('makes a public route explicit with Everyone, without a notice', function (): void {
    expect(permissionOf(Everyone::class)(Mockery::mock(WP_REST_Request::class)))->toBeTrue()
        ->and($this->notices)->toBe([]);
});

it('refuses a route without a permission once a site opts in to private by default', function (): void {
    Filters\expectApplied(WpRestRouteRegistrar::PRIVATE_BY_DEFAULT)->once()->andReturn(true);

    $refusal = permissionOf(null)(Mockery::mock(WP_REST_Request::class));

    expect($this->notices)->toBe([])
        ->and($refusal)->toBeInstanceOf(WP_Error::class)
        ->and($refusal->get_error_code())->toBe('rest_forbidden')
        ->and($refusal->data)->toBe(['status' => 401]);
});

it('still applies a declared permission, whatever the filter says', function (): void {
    Filters\expectApplied(WpRestRouteRegistrar::PRIVATE_BY_DEFAULT)->never();
    Functions\when('is_user_logged_in')->justReturn(true);

    expect(permissionOf(IsLoggedIn::class)(Mockery::mock(WP_REST_Request::class)))->toBeTrue();
});
