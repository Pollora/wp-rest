<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\WpRest\Infrastructure\Services\WpRestRouteRegistrar;
use Pollora\WpRest\Permissions\Can;

enum EventCap: string
{
    case ExportAttendees = 'export_attendees';
}

beforeEach(function (): void {
    Functions\when('rest_authorization_required_code')->justReturn(401);
});

it('allows a user who has the capability', function (): void {
    Functions\expect('current_user_can')->once()->with('export_attendees')->andReturn(true);

    expect((new Can(EventCap::ExportAttendees))->allow(Mockery::mock(WP_REST_Request::class)))->toBeTrue();
});

it('passes the value of a request parameter, for a meta capability', function (): void {
    $request = Mockery::mock(WP_REST_Request::class);
    $request->shouldReceive('get_param')->with('id')->andReturn('42');
    Functions\expect('current_user_can')->once()->with('edit_post', 42)->andReturn(true);

    expect((new Can('edit_post', parameter: 'id'))->allow($request))->toBeTrue();
});

it("refuses with WordPress's authorization status", function (): void {
    Functions\when('current_user_can')->justReturn(false);

    expect((new Can('manage_options'))->allow(Mockery::mock(WP_REST_Request::class)))->toBeInstanceOf(WP_Error::class);
});

it('is accepted as an instance by #[Method] and #[WpRestRoute]', function (): void {
    Functions\when('current_user_can')->justReturn(true);
    $resolve = new ReflectionMethod(WpRestRouteRegistrar::class, 'resolvePermissionCallback');

    $callback = $resolve->invoke(new WpRestRouteRegistrar, new Can('edit_posts'));

    expect($callback(Mockery::mock(WP_REST_Request::class)))->toBeTrue();
});

it('refuses without asking WordPress when the named parameter is absent or not a positive integer', function (mixed $value): void {
    $request = Mockery::mock(WP_REST_Request::class);
    $request->shouldReceive('get_param')->with('id')->andReturn($value);
    Functions\expect('current_user_can')->never();

    $result = (new Can('edit_post', parameter: 'id'))->allow($request);

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->data)->toBe(['status' => 401]);
})->with([[null], [''], ['0'], [0], [-3], ['-3'], ['abc'], ['4abc'], ['4.5'], [[7]], [true]]);
