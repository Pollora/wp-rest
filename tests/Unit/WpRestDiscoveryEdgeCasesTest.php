<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Attributes\WpRestRoute;
use Pollora\Attributes\WpRestRoute\ExplodingAttribute;
use Pollora\Attributes\WpRestRoute\Method;
use Pollora\Attributes\WpRestRoute\PlainAttribute;
use Pollora\Attributes\WpRestRoute\ProbeAttribute;
use Pollora\Discovery\Domain\Contracts\ReflectionCacheInterface;
use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Pollora\Discovery\Infrastructure\Services\ReflectionCache;
use Pollora\WpRest\Infrastructure\Services\WpRestDiscovery;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;
use Spatie\StructureDiscoverer\Data\DiscoveredStructure;

require_once __DIR__.'/../Fixtures/CustomRouteAttributes.php';

#[WpRestRoute('edge/v1', '/abstract')]
abstract class EdgeAbstractController
{
    #[Method('GET')]
    abstract public function index(): array;
}

final class EdgeUndecoratedController
{
    public function index(): array
    {
        return [];
    }
}

#[WpRestRoute('edge/v1', '/custom')]
final class EdgeCustomAttributeController
{
    #[Method('GET'), PlainAttribute]
    public function plain(): array
    {
        return [];
    }

    #[ProbeAttribute]
    public function probed(): void {}

    #[ExplodingAttribute]
    public function exploding(): void {}

    #[Deprecated]
    public function other(): void {}
}

/**
 * Collects the callbacks the discovery hooks on rest_api_init.
 *
 * @return ArrayObject<int, callable>
 */
function captureRestApiInit(): ArrayObject
{
    $callbacks = new ArrayObject;
    Functions\when('add_action')->alias(function (string $hook, callable $callback) use ($callbacks): bool {
        if ($hook === 'rest_api_init') {
            $callbacks[] = $callback;
        }

        return true;
    });

    return $callbacks;
}

function discoverClass(WpRestDiscovery $discovery, string $class): void
{
    $discovery->discover(
        new DiscoveryLocation('', __DIR__),
        DiscoveredClass::fromReflection(new ReflectionClass($class)),
        new ReflectionCache,
    );
}

beforeEach(function (): void {
    ProbeAttribute::$handled = [];
});

describe('WpRestDiscovery::discover', function (): void {
    test('ignores a structure that is not a class', function (): void {
        $discovery = new WpRestDiscovery;

        $discovery->discover(new DiscoveryLocation('', __DIR__), Mockery::mock(DiscoveredStructure::class));

        expect($discovery->getItems()->all())->toBeEmpty();
    });

    test('ignores an abstract class even when it carries #[WpRestRoute]', function (): void {
        $discovery = new WpRestDiscovery;

        discoverClass($discovery, EdgeAbstractController::class);

        expect($discovery->getItems()->all())->toBeEmpty();
    });

    test('ignores a class without #[WpRestRoute]', function (): void {
        $discovery = new WpRestDiscovery;

        discoverClass($discovery, EdgeUndecoratedController::class);

        expect($discovery->getItems()->all())->toBeEmpty();
    });

    test('collects a concrete class decorated with #[WpRestRoute]', function (): void {
        $discovery = new WpRestDiscovery;

        discoverClass($discovery, EdgeCustomAttributeController::class);

        expect($discovery->getItems()->all())->toHaveCount(1);
    });
});

describe('WpRestDiscovery::apply', function (): void {
    test('logs and goes on when a class cannot be registered', function (): void {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once()->withArgs(
            fn (string $message): bool => str_contains($message, 'EdgeCustomAttributeController')
        );

        $discovery = new WpRestDiscovery($logger);
        // Without a reflection cache the class cannot be processed
        $discovery->getItems()->add(new DiscoveryLocation('', __DIR__), [
            'class' => EdgeCustomAttributeController::class,
            'attribute' => new stdClass,
            'structure' => new stdClass,
        ]);

        $discovery->apply();
    });

    test('logs a reflection failure on the class', function (): void {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once()->withArgs(
            fn (string $message): bool => str_starts_with($message, 'Failed to process WP REST route for class Missing\\Controller')
        );

        $discovery = new WpRestDiscovery($logger);
        $discovery->getItems()->add(new DiscoveryLocation('', __DIR__), [
            'class' => 'Missing\\Controller',
            'attribute' => new stdClass,
            'structure' => new stdClass,
            'reflection_cache' => new ReflectionCache,
        ]);

        $discovery->apply();
    });

    test('registers nothing for a class whose attribute list is empty', function (): void {
        $hooks = captureRestApiInit();
        $cache = Mockery::mock(ReflectionCacheInterface::class);
        $cache->shouldReceive('getClassReflection')->andReturn(new ReflectionClass(EdgeUndecoratedController::class));
        $cache->shouldReceive('getClassAttributes')->andReturn([]);
        $cache->shouldNotReceive('getPublicMethods');

        $discovery = new WpRestDiscovery;
        $discovery->getItems()->add(new DiscoveryLocation('', __DIR__), [
            'class' => EdgeUndecoratedController::class,
            'attribute' => new stdClass,
            'structure' => new stdClass,
            'reflection_cache' => $cache,
        ]);

        $discovery->apply();

        expect($hooks)->toHaveCount(0);
    });

    test('logs a reflection failure while reading the public methods', function (): void {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once()->withArgs(
            fn (string $message): bool => str_starts_with($message, 'Failed to process method-level attributes for')
                && str_contains($message, 'no methods')
        );
        $attribute = (new ReflectionClass(EdgeCustomAttributeController::class))->getAttributes(WpRestRoute::class);
        $cache = Mockery::mock(ReflectionCacheInterface::class);
        $cache->shouldReceive('getClassReflection')->andReturn(new ReflectionClass(EdgeCustomAttributeController::class));
        $cache->shouldReceive('getClassAttributes')->andReturn($attribute);
        $cache->shouldReceive('getPublicMethods')->andThrow(new ReflectionException('no methods'));

        $discovery = new WpRestDiscovery($logger);
        $discovery->getItems()->add(new DiscoveryLocation('', __DIR__), [
            'class' => EdgeCustomAttributeController::class,
            'attribute' => new stdClass,
            'structure' => new stdClass,
            'reflection_cache' => $cache,
        ]);

        $discovery->apply();
    });

    test('hands a custom attribute the container, the wrapped controller and its method on rest_api_init', function (): void {
        $hooks = captureRestApiInit();
        $container = Mockery::mock(ContainerInterface::class);

        $discovery = new WpRestDiscovery(container: $container);
        discoverClass($discovery, EdgeCustomAttributeController::class);
        $discovery->apply();

        expect(ProbeAttribute::$handled)->toBe([]);

        foreach ($hooks as $callback) {
            $callback();
        }

        expect(ProbeAttribute::$handled)->toHaveCount(1);
        [$handedContainer, $instance, $context, $attribute] = ProbeAttribute::$handled[0];
        expect($handedContainer)->toBe($container)
            ->and($instance->getRealInstance())->toBeInstanceOf(EdgeCustomAttributeController::class)
            ->and($context)->toBeInstanceOf(ReflectionMethod::class)
            ->and($context->getName())->toBe('probed')
            ->and($attribute)->toBeInstanceOf(ProbeAttribute::class);
    });

    test('logs an attribute that cannot be built and keeps registering the others', function (): void {
        captureRestApiInit();
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once()->withArgs(
            fn (string $message): bool => str_contains($message, EdgeCustomAttributeController::class.'::exploding')
                && str_contains($message, 'attribute cannot be built')
        );

        $discovery = new WpRestDiscovery($logger);
        discoverClass($discovery, EdgeCustomAttributeController::class);
        $discovery->apply();
    });

    test('hooks nothing for attributes outside the WpRestRoute namespace or without a handler', function (): void {
        $hooked = 0;
        Functions\when('add_action')->alias(function () use (&$hooked): bool {
            $hooked++;

            return true;
        });

        $discovery = new WpRestDiscovery(Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing());
        discoverClass($discovery, EdgeCustomAttributeController::class);
        $discovery->apply();

        // The #[Method] and the #[ProbeAttribute]; #[PlainAttribute] has no handler and #[Deprecated] is not ours
        expect($hooked)->toBe(2);
    });
});
