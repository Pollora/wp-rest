<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\WpRest\Permissions\IsAdmin;

it('allows a user who can manage options', function (): void {
    Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(true);

    expect((new IsAdmin)->allow(Mockery::mock(WP_REST_Request::class)))->toBeTrue();
});

it('refuses anyone else with a 403 rest_forbidden error', function (): void {
    Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(false);

    $result = (new IsAdmin)->allow(Mockery::mock(WP_REST_Request::class));

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->code)->toBe('rest_forbidden')
        ->and($result->data)->toBe(['status' => 403]);
});
