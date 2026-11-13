<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Attributes\WpRestRoute\Method;
use Pollora\WpRest\Infrastructure\Services\WpRestAttributableWrapper;
use Pollora\WpRest\Infrastructure\Services\WpRestRouteRegistrar;
use Pollora\WpRest\Permissions\Everyone;

final class ArgumentsController
{
    public function show(WP_REST_Request $request, int|string $id): array
    {
        return ['request' => $request, 'id' => $id];
    }
}

/**
 * The route arguments WordPress was given for ArgumentsController::show().
 *
 * @return array<string, mixed>
 */
function registeredArgs(): array
{
    $registered = [];
    Functions\when('register_rest_route')->alias(function (string $namespace, string $route, array $args) use (&$registered): bool {
        $registered = $args;

        return true;
    });

    (new WpRestRouteRegistrar)->register(
        new WpRestAttributableWrapper(new ArgumentsController, 'app/v1', '/items/(?P<id>\\d+)', Everyone::class),
        new ReflectionMethod(ArgumentsController::class, 'show'),
        new Method('GET'),
    );

    return $registered;
}

it('calls the handler with the request for a WP_REST_Request parameter and the route value for the others', function (): void {
    $request = Mockery::mock(WP_REST_Request::class);
    $request->shouldReceive('get_param')->with('id')->andReturn('7');

    $result = registeredArgs()['callback']($request);

    expect($result['request'])->toBe($request)
        ->and($result['id'])->toBe('7');
});

it('declares a validator for each named route parameter', function (): void {
    $validate = registeredArgs()['args']['id']['validate_callback'];

    expect($validate('12'))->toBeTrue()
        ->and($validate(12))->toBeTrue()
        ->and($validate(['12']))->toBeFalse();
});
