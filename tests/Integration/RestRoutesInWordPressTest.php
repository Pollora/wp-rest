<?php

declare(strict_types=1);

use Pollora\Attributes\WpRestRoute;
use Pollora\Attributes\WpRestRoute\Method;
use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Pollora\Discovery\Infrastructure\Services\ReflectionCache;
use Pollora\WpRest\Infrastructure\Services\WpRestAttributableWrapper;
use Pollora\WpRest\Infrastructure\Services\WpRestDiscovery;
use Pollora\WpRest\Infrastructure\Services\WpRestRouteRegistrar;
use Pollora\WpRest\Permissions\Can;
use Pollora\WpRest\Permissions\Everyone;
use Pollora\WpRest\Permissions\IsAdmin;
use Pollora\WpRest\Permissions\IsLoggedIn;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;

/**
 * Routes declared with attributes, registered on `rest_api_init` and answered by the real REST server: what the
 * permission callbacks, the route arguments and the handler's parameters do when WordPress calls them.
 *
 * The unit suite stubs `register_rest_route()`, so it never sees a method list, a route pattern or a permission
 * callback refused by WordPress itself.
 */
#[WpRestRoute('wpint/v1', '/public')]
final class WpintPublicController
{
    #[Method('GET', permissionCallback: Everyone::class)]
    public function show(): array
    {
        return ['visibility' => 'public'];
    }
}

#[WpRestRoute('wpint/v1', '/forgotten')]
final class WpintForgottenController
{
    // No permission anywhere: refused unless the site opts out.
    #[Method('GET')]
    public function show(): array
    {
        return ['visibility' => 'forgotten'];
    }
}

#[WpRestRoute('wpint/v1', '/members', permissionCallback: IsLoggedIn::class)]
final class WpintMembersController
{
    // The method's permission replaces the route's.
    #[Method('GET')]
    public function index(): array
    {
        return ['visibility' => 'members'];
    }

    #[Method('POST', permissionCallback: IsAdmin::class)]
    public function create(): array
    {
        return ['visibility' => 'admins'];
    }
}

#[WpRestRoute('wpint/v1', '/items/(?P<id>\d+)')]
final class WpintItemsController
{
    #[Method(['GET', 'PUT'], permissionCallback: new Can('edit_post', parameter: 'id'))]
    public function edit(WP_REST_Request $request, string $id): array
    {
        return ['id' => $id, 'method' => $request->get_method(), 'sameRequest' => $request instanceof WP_REST_Request];
    }
}

#[WpRestRoute('wpint/v1', '/export', permissionCallback: new Can('manage_options'))]
final class WpintExportController
{
    #[Method('GET')]
    public function export(): array
    {
        return ['exported' => true];
    }
}

/**
 * Registers the routes of the fixture controllers, boots a fresh REST server and returns it.
 */
function wpintRestServer(string ...$controllers): WP_REST_Server
{
    $discovery = new WpRestDiscovery;

    foreach ($controllers as $controller) {
        $discovery->discover(new DiscoveryLocation('', __DIR__), DiscoveredClass::fromReflection(new ReflectionClass($controller)), new ReflectionCache);
    }

    $discovery->apply();

    // `rest_api_init` fires when the server is created.
    $GLOBALS['wp_rest_server'] = null;

    return rest_get_server();
}

function wpintRequest(string $method, string $route): WP_REST_Response
{
    return rest_do_request(new WP_REST_Request($method, $route));
}

function wpintUser(string $role, string $login): int
{
    $id = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login.'@example.com', 'role' => $role]);
    expect($id)->toBeInt();
    $GLOBALS['wpint']['users'][] = $id;

    return $id;
}

beforeEach(function (): void {
    $GLOBALS['wpint'] = [
        // WP_Hook objects are changed in place: the snapshot needs copies of them.
        'hooks' => array_map(static fn (WP_Hook $hook): WP_Hook => clone $hook, $GLOBALS['wp_filter']),
        'server' => $GLOBALS['wp_rest_server'] ?? null,
        'request' => $_REQUEST,
        'users' => [],
        'posts' => [],
    ];

    // The cheapest bcrypt cost: a test user does not need a strong hash, and the default one takes a second.
    add_filter('wp_hash_password_options', static fn (array $options): array => ['cost' => 4] + $options);
    // A route that declares no permission tells WordPress it is doing it wrong, which WP_DEBUG turns into a warning.
    add_filter('doing_it_wrong_trigger_error', '__return_false');
});

afterEach(function (): void {
    require_once ABSPATH.'wp-admin/includes/user.php';

    wp_set_current_user(0);

    foreach ($GLOBALS['wpint']['posts'] as $id) {
        wp_delete_post($id, true);
    }

    foreach ($GLOBALS['wpint']['users'] as $id) {
        wp_delete_user($id);
    }

    $GLOBALS['wp_filter'] = array_map(static fn (WP_Hook $hook): WP_Hook => clone $hook, $GLOBALS['wpint']['hooks']);
    $GLOBALS['wp_rest_server'] = $GLOBALS['wpint']['server'];
    $_REQUEST = $GLOBALS['wpint']['request'];
});

it('registers the routes and the methods with WordPress, which lists them', function (): void {
    $routes = wpintRestServer(WpintPublicController::class, WpintMembersController::class, WpintItemsController::class)->get_routes('wpint/v1');

    expect($routes)->toHaveKeys(['/wpint/v1/public', '/wpint/v1/members', '/wpint/v1/items/(?P<id>\d+)'])
        ->and(array_keys($routes['/wpint/v1/members'][0]['methods']))->toBe(['GET'])
        ->and(array_keys($routes['/wpint/v1/members'][1]['methods']))->toBe(['POST'])
        ->and(array_keys($routes['/wpint/v1/items/(?P<id>\d+)'][0]['methods']))->toBe(['GET', 'PUT'])
        ->and($routes['/wpint/v1/items/(?P<id>\d+)'][0]['args'])->toHaveKey('id');
});

it('answers a public route to everyone, and refuses the route that declares no permission', function (): void {
    wpintRestServer(WpintPublicController::class, WpintForgottenController::class);

    $open = wpintRequest('GET', '/wpint/v1/public');
    $forgotten = wpintRequest('GET', '/wpint/v1/forgotten');

    expect($open->get_status())->toBe(200)
        ->and($open->get_data())->toBe(['visibility' => 'public'])
        ->and($forgotten->get_status())->toBe(401)
        ->and($forgotten->get_data()['code'])->toBe('rest_forbidden');

    // Logged in, the same refusal is a 403.
    wp_set_current_user(1);

    expect(wpintRequest('GET', '/wpint/v1/forgotten')->get_status())->toBe(403);
});

it('keeps the route that declares no permission public when the site opts out, and says which route to fix', function (): void {
    add_filter('pollora/wp-rest/private_by_default', '__return_false');
    $notices = [];
    add_action('doing_it_wrong_run', static function (string $function, string $message) use (&$notices): void {
        $notices[] = $message;
    }, 10, 2);

    wpintRestServer(WpintForgottenController::class);

    expect(wpintRequest('GET', '/wpint/v1/forgotten')->get_status())->toBe(200)
        ->and($notices)->toHaveCount(1)
        ->and($notices[0])->toContain('wpint/v1/forgotten');
});

it('checks the permission of the route, and the one of the method when it declares its own', function (): void {
    $subscriber = wpintUser('subscriber', 'wpint_member');
    wpintRestServer(WpintMembersController::class);

    // Refused, as 401 or 403: IsLoggedIn answers 403 whoever asks.
    expect(wpintRequest('GET', '/wpint/v1/members')->get_status())->toBeIn([401, 403])
        ->and(wpintRequest('GET', '/wpint/v1/members')->get_data()['code'])->toBe('rest_forbidden');

    wp_set_current_user($subscriber);
    expect(wpintRequest('GET', '/wpint/v1/members')->get_status())->toBe(200)
        ->and(wpintRequest('POST', '/wpint/v1/members')->get_status())->toBe(403);

    wp_set_current_user(1);
    expect(wpintRequest('POST', '/wpint/v1/members')->get_data())->toBe(['visibility' => 'admins']);
});

it('passes the request and the route value to the handler, and the capability to WordPress', function (): void {
    $author = wpintUser('author', 'wpint_author');
    $stranger = wpintUser('author', 'wpint_stranger');
    $post = wp_insert_post(['post_title' => 'Mine', 'post_status' => 'publish', 'post_author' => $author]);
    $GLOBALS['wpint']['posts'][] = $post;
    wpintRestServer(WpintItemsController::class);

    wp_set_current_user($author);
    $mine = wpintRequest('PUT', '/wpint/v1/items/'.$post);

    expect($mine->get_status())->toBe(200)
        ->and($mine->get_data())->toBe(['id' => (string) $post, 'method' => 'PUT', 'sameRequest' => true]);

    // Another author may not edit it: `edit_post` is a meta capability WordPress maps on the post.
    wp_set_current_user($stranger);
    expect(wpintRequest('PUT', '/wpint/v1/items/'.$post)->get_status())->toBe(403);

    wp_set_current_user(0);
    expect(wpintRequest('PUT', '/wpint/v1/items/'.$post)->get_status())->toBe(401);
});

it('does not give the user back to a request that WordPress has just logged out', function (): void {
    // The routes are registered while the cookie user is known (WordPress sets it at `init`)...
    wp_set_current_user(1);
    wpintRestServer(WpintMembersController::class);

    // ...then, for a cookie request without a nonce, rest_cookie_check_errors() acts as if nobody was logged in.
    $GLOBALS['wp_rest_auth_cookie'] = true;
    $_REQUEST = [];
    $_SERVER['HTTP_X_WP_NONCE'] = null;
    unset($_SERVER['HTTP_X_WP_NONCE']);

    expect(rest_cookie_check_errors(null))->toBeTrue()
        ->and(get_current_user_id())->toBe(0)
        ->and(wpintRequest('GET', '/wpint/v1/members')->get_status())->toBeIn([401, 403])
        ->and(get_current_user_id())->toBe(0);

    unset($GLOBALS['wp_rest_auth_cookie']);
});

it('does not match a route value that the pattern refuses', function (): void {
    wp_set_current_user(1);
    wpintRestServer(WpintItemsController::class);

    expect(wpintRequest('GET', '/wpint/v1/items/not-a-number')->get_status())->toBe(404);
});

it('checks a capability on the current user with Can, and an administrator capability with IsAdmin', function (): void {
    $author = wpintUser('author', 'wpint_plain_author');
    wpintRestServer(WpintExportController::class);

    wp_set_current_user($author);
    expect(wpintRequest('GET', '/wpint/v1/export')->get_status())->toBe(403);

    wp_set_current_user(1);
    expect(wpintRequest('GET', '/wpint/v1/export')->get_data())->toBe(['exported' => true]);
});

it('hands WordPress a permission callback it accepts when the route is registered directly', function (): void {
    // The registrar alone, without discovery: the arguments WordPress validates in register_rest_route().
    $controller = new WpintExportController;
    add_action('rest_api_init', static function () use ($controller): void {
        (new WpRestRouteRegistrar)->register(
            new WpRestAttributableWrapper($controller, 'wpint/v1', '/direct', IsAdmin::class),
            new ReflectionMethod($controller, 'export'),
            new Method('GET'),
        );
    });

    $server = wpintRestServer();
    wp_set_current_user(1);

    expect($server->get_routes('wpint/v1'))->toHaveKey('/wpint/v1/direct')
        ->and(wpintRequest('GET', '/wpint/v1/direct')->get_data())->toBe(['exported' => true]);
});

it('refuses a Can that names a parameter the request does not have, even when a post is the global one', function (): void {
    // `current_user_can('edit_post', null)` could be read as "the global post": WordPress denies it instead.
    wp_set_current_user(1);
    $GLOBALS['post'] = get_post(1);

    $result = (new Can('edit_post', parameter: 'id'))->allow(new WP_REST_Request('PUT', '/wpint/v1/items'));

    unset($GLOBALS['post']);

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->get_error_code())->toBe('rest_forbidden');
});
