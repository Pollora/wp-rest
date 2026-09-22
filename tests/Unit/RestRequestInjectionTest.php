<?php

declare(strict_types=1);

use Pollora\Attributes\WpRestRoute\Method;

/**
 * Which arguments a REST handler is invoked with.
 *
 * Arguments were taken from the route parameters alone, so a handler declaring
 * `index(WP_REST_Request $request)` had `get_param('request')` looked up, found
 * nothing, and was called with null — a TypeError before a line of it ran. The
 * type hint is what says "give me the request".
 */
function expectsRequest(string $signatureMethod): bool
{
    $probe = new class
    {
        public function withRequest(WP_REST_Request $request): void {}

        public function withRouteParam(string $id): void {}

        public function withUntyped($id): void {}

        public function withBuiltin(int $page): void {}
    };

    $param = (new ReflectionMethod($probe, $signatureMethod))->getParameters()[0];

    $method = (new ReflectionClass(Method::class))->getMethod('expectsTheRequest');

    return $method->invoke(new Method('GET'), $param);
}

describe('Method::expectsTheRequest()', function (): void {
    it('gives the request to a parameter that asks for it by type', function (): void {
        expect(expectsRequest('withRequest'))->toBeTrue();
    });

    it('leaves a route parameter alone', function (): void {
        expect(expectsRequest('withRouteParam'))->toBeFalse();
    });

    it('leaves an untyped parameter alone', function (): void {
        expect(expectsRequest('withUntyped'))->toBeFalse();
    });

    it('leaves a builtin type alone', function (): void {
        // int, string and friends name route values, never the request.
        expect(expectsRequest('withBuiltin'))->toBeFalse();
    });
});
