<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Fixtures\Mutation\FirstSharedController;
use Fixtures\Mutation\FourthSharedController;
use Fixtures\Mutation\NamespacedController;
use Fixtures\Mutation\OrderedAttributesController;
use Fixtures\Mutation\SecondSharedController;
use Fixtures\Mutation\ThirdSharedController;
use Pollora\Attributes\WpRestRoute;
use Pollora\Attributes\WpRestRoute\ExplodingAttribute;
use Pollora\Attributes\WpRestRoute\Method;
use Pollora\Attributes\WpRestRoute\ProbeAttribute;
use Pollora\Discovery\Domain\Contracts\ReflectionCacheInterface;
use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Pollora\Discovery\Infrastructure\Services\ReflectionCache;
use Pollora\WpRest\Infrastructure\Services\WpRestAttributableWrapper;
use Pollora\WpRest\Infrastructure\Services\WpRestDiscovery;
use Pollora\WpRest\Infrastructure\Services\WpRestRouteRegistrar;
use Pollora\WpRest\Permissions\Can;
use Pollora\WpRest\Permissions\Everyone;
use Pollora\WpRest\Permissions\IsAdmin;
use Pollora\WpRest\Permissions\IsLoggedIn;
use Psr\Log\LoggerInterface;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;

require_once __DIR__.'/../Fixtures/CustomRouteAttributes.php';
require_once __DIR__.'/../Fixtures/NamespacedControllers.php';

enum MutationIntCap: int
{
    case Ten = 10;
}

#[WpRestRoute('ns/v1', '/exploding')]
final class MutationExplodingController
{
    #[ExplodingAttribute]
    #[ProbeAttribute]
    public function exploding(): void {}
}

class RequestSubclass extends WP_REST_Request {}

final class MutationPlainController
{
    public function show(): array
    {
        return [];
    }
}

final class MutationMethodPermissionController
{
    public function show(): array
    {
        return [];
    }
}

/**
 * @return array<string, mixed> the arguments register_rest_route received
 */
function mutationRegister(WpRestAttributableWrapper $wrapper, Method $method): array
{
    $registered = [];
    Functions\when('register_rest_route')->alias(function (string $namespace, string $route, array $args) use (&$registered): bool {
        $registered = $args;

        return true;
    });

    (new WpRestRouteRegistrar)->register($wrapper, new ReflectionMethod(MutationMethodPermissionController::class, 'show'), $method);

    return $registered;
}

function mutationDiscover(WpRestDiscovery $discovery, string $class): void
{
    $discovery->discover(
        new DiscoveryLocation('', __DIR__),
        DiscoveredClass::fromReflection(new ReflectionClass($class)),
        new ReflectionCache,
    );
}

beforeEach(function (): void {
    Functions\when('rest_authorization_required_code')->justReturn(401);
    ProbeAttribute::$handled = [];
});

describe('permission refusals', function (): void {
    it('refuses a visitor with the full 403 error of IsLoggedIn', function (): void {
        Functions\when('is_user_logged_in')->justReturn(false);

        $result = (new IsLoggedIn)->allow(Mockery::mock(WP_REST_Request::class));

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->code)->toBe('rest_forbidden')
            ->and($result->message)->toBe('You do not have permission to access this endpoint.')
            ->and($result->data)->toBe(['status' => 403]);
    });

    it('allows a logged in user with IsLoggedIn', function (): void {
        Functions\when('is_user_logged_in')->justReturn(true);

        expect((new IsLoggedIn)->allow(Mockery::mock(WP_REST_Request::class)))->toBeTrue();
    });

    it('refuses with the complete message from IsAdmin', function (): void {
        Functions\when('current_user_can')->justReturn(false);

        expect((new IsAdmin)->allow(Mockery::mock(WP_REST_Request::class))->message)
            ->toBe('You do not have permission to access this endpoint.');
    });

    it('refuses with the complete error and WordPress status from Can', function (): void {
        Functions\when('current_user_can')->justReturn(false);

        $result = (new Can('manage_options'))->allow(Mockery::mock(WP_REST_Request::class));

        expect($result->code)->toBe('rest_forbidden')
            ->and($result->message)->toBe('You do not have permission to access this endpoint.')
            ->and($result->data)->toBe(['status' => 401]);
    });

    it('passes the backing value of an int enum as a string capability', function (): void {
        $received = null;
        Functions\when('current_user_can')->alias(function (mixed $capability) use (&$received): bool {
            $received = $capability;

            return true;
        });

        (new Can(MutationIntCap::Ten))->allow(Mockery::mock(WP_REST_Request::class));

        expect($received)->toBe('10');
    });

    it('is allowed by Everyone', function (): void {
        expect((new Everyone)->allow(Mockery::mock(WP_REST_Request::class)))->toBeTrue();
    });
});

describe('WpRestRouteRegistrar', function (): void {
    it('prefers the permission of the method over the one of the class', function (): void {
        Functions\when('is_user_logged_in')->justReturn(false);

        $args = mutationRegister(
            new WpRestAttributableWrapper(new MutationMethodPermissionController, 'app/v1', '/x', IsLoggedIn::class),
            new Method('GET', permissionCallback: Everyone::class),
        );

        expect($args['permission_callback'](Mockery::mock(WP_REST_Request::class)))->toBeTrue();
    });

    it('falls back on the permission of the class when the method declares none', function (): void {
        Functions\when('is_user_logged_in')->justReturn(false);

        $args = mutationRegister(
            new WpRestAttributableWrapper(new MutationMethodPermissionController, 'app/v1', '/x', IsLoggedIn::class),
            new Method('GET'),
        );

        expect($args['permission_callback'](Mockery::mock(WP_REST_Request::class)))->toBeInstanceOf(WP_Error::class);
    });

    it('hands the refusal of a route without a permission the exact message', function (): void {
        $args = mutationRegister(
            new WpRestAttributableWrapper(new MutationMethodPermissionController, 'app/v1', '/x'),
            new Method('GET'),
        );

        expect($args['permission_callback']()->message)->toBe('This endpoint declares no permission.');
    });

    it('names the route without stray slashes in the notice', function (): void {
        Functions\when('apply_filters')->justReturn(false);
        $notices = [];
        Functions\when('_doing_it_wrong')->alias(function (string $function, string $message) use (&$notices): void {
            $notices[] = $message;
        });

        mutationRegister(
            new WpRestAttributableWrapper(new MutationMethodPermissionController, '/app/v1/', '//status'),
            new Method('GET'),
        );

        expect($notices)->toHaveCount(1)
            ->and($notices[0])->toStartWith('The REST route app/v1/status declares no permission');
    });

    it('invalid permission handler error carries its message', function (): void {
        $args = mutationRegister(
            new WpRestAttributableWrapper(new MutationMethodPermissionController, 'app/v1', '/x', 'Missing\\Handler'),
            new Method('GET'),
        );

        expect($args['permission_callback']()->message)->toBe('Invalid permission handler.');
    });

    it('lets a subclass of WP_REST_Request receive the request, and a foreign class not', function (): void {
        $probe = new class
        {
            public function sub(RequestSubclass $request): void {}

            public function foreign(Countable $value): void {}
        };
        $expects = new ReflectionMethod(WpRestRouteRegistrar::class, 'expectsTheRequest');
        $registrar = new WpRestRouteRegistrar;

        $sub = (new ReflectionMethod($probe, 'sub'))->getParameters()[0];
        $foreign = (new ReflectionMethod($probe, 'foreign'))->getParameters()[0];

        expect($expects->invoke($registrar, $sub))->toBeTrue()
            ->and($expects->invoke($registrar, $foreign))->toBeFalse();
    });
});

describe('WpRestDiscovery bookkeeping', function (): void {
    it('collects the fully qualified class, its attribute and its structure', function (): void {
        $discovery = new WpRestDiscovery;
        $structure = DiscoveredClass::fromReflection(new ReflectionClass(NamespacedController::class));

        $discovery->discover(new DiscoveryLocation('', __DIR__), $structure, new ReflectionCache);

        $items = array_values($discovery->getItems()->all());
        $item = is_array($items[0]) && isset($items[0]['class']) ? $items[0] : array_values($items[0])[0];

        expect($item['class'])->toBe(NamespacedController::class)
            ->and($item['attribute']->class)->toBe(WpRestRoute::class)
            ->and($item['structure'])->toBe($structure)
            ->and($item)->toHaveKey('reflection_cache');
    });

    it('logs the complete message when a class cannot be registered, and survives without a logger', function (): void {
        $failing = Mockery::mock(ReflectionCacheInterface::class);
        $failing->shouldReceive('getClassReflection')->andThrow(new RuntimeException('the cache is broken'));
        $item = [
            'class' => NamespacedController::class,
            'attribute' => new stdClass,
            'structure' => new stdClass,
            'reflection_cache' => $failing,
        ];

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once()->with(
            'Failed to register WP REST route from class '.NamespacedController::class.': the cache is broken'
        );
        $withLogger = new WpRestDiscovery($logger);
        $withLogger->getItems()->add(new DiscoveryLocation('', __DIR__), $item);
        $withLogger->apply();

        $silent = new WpRestDiscovery;
        $silent->getItems()->add(new DiscoveryLocation('', __DIR__), $item);

        // An exception escaping here fails the test: Pest's not->toThrow() lets it through
        $silent->apply();

        expect($silent->getItems()->all())->toHaveCount(1);
    });

    it('logs the exact reflection failure of a class, and survives without a logger', function (): void {
        $item = [
            'class' => 'Missing\\Controller',
            'attribute' => new stdClass,
            'structure' => new stdClass,
            'reflection_cache' => new ReflectionCache,
        ];

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once()->with(Mockery::on(
            fn (string $message): bool => str_starts_with($message, 'Failed to process WP REST route for class Missing\\Controller: ')
                && strlen($message) > strlen('Failed to process WP REST route for class Missing\\Controller: ')
        ));
        $withLogger = new WpRestDiscovery($logger);
        $withLogger->getItems()->add(new DiscoveryLocation('', __DIR__), $item);
        $withLogger->apply();

        $silent = new WpRestDiscovery;
        $silent->getItems()->add(new DiscoveryLocation('', __DIR__), $item);

        // An exception escaping here fails the test: Pest's not->toThrow() lets it through
        $silent->apply();

        expect($silent->getItems()->all())->toHaveCount(1);
    });

    it('logs the failure of reading methods in full, and survives without a logger', function (): void {
        $attribute = (new ReflectionClass(NamespacedController::class))->getAttributes(WpRestRoute::class);
        $cache = Mockery::mock(ReflectionCacheInterface::class);
        $cache->shouldReceive('getClassReflection')->andReturn(new ReflectionClass(NamespacedController::class));
        $cache->shouldReceive('getClassAttributes')->andReturn($attribute);
        $cache->shouldReceive('getPublicMethods')->andThrow(new ReflectionException('no methods'));
        $item = [
            'class' => NamespacedController::class,
            'attribute' => new stdClass,
            'structure' => new stdClass,
            'reflection_cache' => $cache,
        ];

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once()->with('Failed to process method-level attributes for '.NamespacedController::class.': no methods');
        $withLogger = new WpRestDiscovery($logger);
        $withLogger->getItems()->add(new DiscoveryLocation('', __DIR__), $item);
        $withLogger->apply();

        $silent = new WpRestDiscovery;
        $silent->getItems()->add(new DiscoveryLocation('', __DIR__), $item);

        // An exception escaping here fails the test: Pest's not->toThrow() lets it through
        $silent->apply();

        expect($silent->getItems()->all())->toHaveCount(1);
    });

    it('logs the complete message of an attribute that cannot be built, and survives without a logger', function (): void {
        $hooked = 0;
        Functions\when('add_action')->alias(function () use (&$hooked): bool {
            $hooked++;

            return true;
        });

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once()->with(
            'Failed to process method attribute for '.MutationExplodingController::class.'::exploding: attribute cannot be built'
        );
        $withLogger = new WpRestDiscovery($logger);
        mutationDiscover($withLogger, MutationExplodingController::class);
        $withLogger->apply();
        $hooked = 0;

        $silent = new WpRestDiscovery;
        mutationDiscover($silent, MutationExplodingController::class);

        // An exception escaping here fails the test: Pest's not->toThrow() lets it through
        $silent->apply();

        expect($silent->getItems()->all())->toHaveCount(1)
            ->and($hooked)->toBe(1);
    });

    it('keeps reading the attributes of a method after one that is not ours', function (): void {
        $hooked = 0;
        Functions\when('add_action')->alias(function () use (&$hooked): bool {
            $hooked++;

            return true;
        });

        $discovery = new WpRestDiscovery;
        mutationDiscover($discovery, OrderedAttributesController::class);
        $discovery->apply();

        // #[Deprecated] is skipped; #[ProbeAttribute] and #[Method] are both hooked
        expect($hooked)->toBe(2);
    });

    it('wraps each controller once and keys the wrapper on class, namespace and route', function (): void {
        $hooks = [];
        Functions\when('add_action')->alias(function (string $hook, callable $callback) use (&$hooks): bool {
            $hooks[] = $callback;

            return true;
        });

        $discovery = new WpRestDiscovery;
        foreach ([FirstSharedController::class, SecondSharedController::class, ThirdSharedController::class] as $class) {
            mutationDiscover($discovery, $class);
        }
        $discovery->apply();
        $discovery->apply();

        foreach ($hooks as $callback) {
            $callback();
        }

        $wrappers = array_map(fn (array $call): WpRestAttributableWrapper => $call[1], ProbeAttribute::$handled);

        expect($wrappers)->toHaveCount(6)
            ->and($wrappers[0])->toBe($wrappers[3])
            ->and($wrappers[1])->toBe($wrappers[4])
            ->and($wrappers[2])->toBe($wrappers[5])
            ->and($wrappers[0])->not->toBe($wrappers[1])
            ->and($wrappers[0])->not->toBe($wrappers[2])
            ->and($wrappers[1])->not->toBe($wrappers[2])
            ->and([$wrappers[0]->route, $wrappers[1]->route, $wrappers[2]->route])->toBe(['/first', '/second', '/first'])
            ->and([$wrappers[0]->namespace, $wrappers[2]->namespace])->toBe(['ns/v1', 'other/v1']);
    });

    it('does not share a wrapper between two classes declaring the same namespace and route', function (): void {
        $hooks = [];
        Functions\when('add_action')->alias(function (string $hook, callable $callback) use (&$hooks): bool {
            $hooks[] = $callback;

            return true;
        });

        $discovery = new WpRestDiscovery;
        mutationDiscover($discovery, FirstSharedController::class);
        mutationDiscover($discovery, FourthSharedController::class);
        $discovery->apply();

        foreach ($hooks as $callback) {
            $callback();
        }

        [$first, $fourth] = array_map(fn (array $call): WpRestAttributableWrapper => $call[1], ProbeAttribute::$handled);

        expect($first)->not->toBe($fourth)
            ->and($first->getRealInstance())->toBeInstanceOf(FirstSharedController::class)
            ->and($fourth->getRealInstance())->toBeInstanceOf(FourthSharedController::class);
    });

    it('keys the wrapper of one class on its namespace and on its route', function (): void {
        $hooks = [];
        Functions\when('add_action')->alias(function (string $hook, callable $callback) use (&$hooks): bool {
            $hooks[] = $callback;

            return true;
        });

        $attributes = [
            (new ReflectionClass(FirstSharedController::class))->getAttributes(WpRestRoute::class),
            (new ReflectionClass(SecondSharedController::class))->getAttributes(WpRestRoute::class),
            (new ReflectionClass(ThirdSharedController::class))->getAttributes(WpRestRoute::class),
        ];
        $cache = Mockery::mock(ReflectionCacheInterface::class);
        $cache->shouldReceive('getClassReflection')->andReturn(new ReflectionClass(FirstSharedController::class));
        $cache->shouldReceive('getClassAttributes')->andReturn(...$attributes);
        $cache->shouldReceive('getPublicMethods')->andReturn([new ReflectionMethod(FirstSharedController::class, 'probe')]);

        $discovery = new WpRestDiscovery;
        foreach ($attributes as $unused) {
            $discovery->getItems()->add(new DiscoveryLocation('', __DIR__), [
                'class' => FirstSharedController::class,
                'attribute' => new stdClass,
                'structure' => new stdClass,
                'reflection_cache' => $cache,
            ]);
        }
        $discovery->apply();

        foreach ($hooks as $callback) {
            $callback();
        }

        $wrappers = array_map(fn (array $call): WpRestAttributableWrapper => $call[1], ProbeAttribute::$handled);

        expect($wrappers)->toHaveCount(3)
            ->and($wrappers[0])->not->toBe($wrappers[1])
            ->and($wrappers[0])->not->toBe($wrappers[2])
            ->and($wrappers[1])->not->toBe($wrappers[2]);
    });
});
