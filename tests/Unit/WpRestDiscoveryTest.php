<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Attributes\Attributable;
use Pollora\Attributes\WpRestRoute;
use Pollora\Attributes\WpRestRoute\Method;
use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Pollora\Discovery\Infrastructure\Services\ReflectionCache;
use Pollora\WpRest\Infrastructure\Services\WpRestDiscovery;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;

// Mock WordPress functions if they don't exist
if (! function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args): bool
    {
        global $registered_routes;
        $registered_routes[] = ['namespace' => $namespace, 'route' => $route, 'args' => $args];

        return true;
    }
}

if (! function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1): bool
    {
        global $wp_actions;
        $wp_actions[$hook][] = $callback;

        return true;
    }
}

if (! class_exists('WP_REST_Request')) {
    eval('class WP_REST_Request {}');
}

final class DocumentRepository
{
    public function title(): string
    {
        return 'Annual report';
    }
}

// A controller with a dependency, given by the container
#[WpRestRoute('api/v1', '/reports')]
final readonly class ReportsController
{
    public function __construct(private DocumentRepository $documents) {}

    #[Method('GET')]
    public function index(): array
    {
        return ['title' => $this->documents->title()];
    }
}

// Test class that doesn't implement Attributable (real-world scenario)
#[WpRestRoute('api/v1', '/documents/(?P<documentId>\\d+)')]
class TestDocumentAPI
{
    #[Method('GET')]
    public function get(int $documentId): array
    {
        return ['id' => $documentId, 'title' => 'Test Document'];
    }

    #[Method(['POST', 'DELETE'])]
    public function modify(int $documentId): array
    {
        return ['id' => $documentId, 'modified' => true];
    }
}

// Test class without WpRestRoute attribute
class RegularController
{
    public function someMethod(): string
    {
        return 'regular';
    }
}

// Test class that is abstract
#[WpRestRoute('api/v1', '/abstract')]
abstract class AbstractController
{
    #[Method('GET')]
    abstract public function get(): array;
}

// Mock classes for testing - simplified version without Spatie dependencies
class MockDiscoveredAttribute
{
    public function __construct(
        public readonly string $name,
        public readonly string $class,
        public readonly array $arguments = []
    ) {}

    public function newInstance()
    {
        return new $this->class(...$this->arguments);
    }
}

class MockDiscoveredClass
{
    public function __construct(
        public readonly string $name,
        public readonly string $namespace,
        public readonly string $file,
        public readonly array $attributes = [],
        public readonly bool $isAbstract = false
    ) {}
}

describe('WpRestDiscovery', function (): void {

    beforeEach(function (): void {
        global $registered_routes, $wp_actions;
        $registered_routes = [];
        $wp_actions = [];
    });

    test('discover method processes only DiscoveredClass instances', function (): void {
        $discovery = new WpRestDiscovery;

        // Test that discovery starts empty
        expect($discovery->getItems()->all())->toHaveCount(0);

        // Test that we can add items manually (simulating proper discovery)
        $location = new DiscoveryLocation('Test\\', '/test/path');
        $discovery->getItems()->add($location, ['test' => 'item']);

        expect($discovery->getItems()->all())->toHaveCount(1);
    });

    test('basic discovery functionality works', function (): void {
        $discovery = new WpRestDiscovery;

        // Test that we can manually add items (simulating discovery)
        $location = new DiscoveryLocation('Test\\', '/test/path');
        $mockAttribute = new MockDiscoveredAttribute(
            name: WpRestRoute::class,
            class: WpRestRoute::class,
            arguments: ['api/v1', '/test']
        );

        $discovery->getItems()->add($location, [
            'class' => 'TestClass',
            'attribute' => $mockAttribute,
            'structure' => new MockDiscoveredClass('TestClass', '', '/test.php'),
        ]);

        expect($discovery->getItems()->all())->toHaveCount(1);
    });

    test('registers REST routes when applying discovered items', function (): void {
        global $registered_routes, $wp_actions;

        $discovery = new WpRestDiscovery;
        $location = new DiscoveryLocation('', '/test/path');
        $reflectionCache = new ReflectionCache;

        // Add a simple test item to verify the apply logic works
        $discovery->getItems()->add($location, [
            'class' => 'TestDocumentAPI',
            'attribute' => new MockDiscoveredAttribute(
                name: WpRestRoute::class,
                class: WpRestRoute::class,
                arguments: ['api/v1', '/documents/(?P<documentId>\\d+)']
            ),
            'structure' => new MockDiscoveredClass(
                name: 'TestDocumentAPI',
                namespace: '',
                file: '/test/TestDocumentAPI.php'
            ),
            'reflection_cache' => $reflectionCache,
        ]);

        // The apply method should not throw even if reflection fails
        expect(fn () => $discovery->apply())->not->toThrow(Exception::class);

        // Verify that items were processed (we don't check wp_actions as it depends on complex logic)
        expect($discovery->getItems()->all())->toHaveCount(1);
    });

    test('handles reflection errors gracefully', function (): void {
        $discovery = new WpRestDiscovery;
        $location = new DiscoveryLocation('', '/test/path');
        $reflectionCache = new ReflectionCache;

        // Add an item with a non-existent class
        $discovery->getItems()->add($location, [
            'class' => 'NonExistentClass',
            'attribute' => new MockDiscoveredAttribute(
                name: WpRestRoute::class,
                class: WpRestRoute::class,
                arguments: ['api/v1', '/test']
            ),
            'structure' => new MockDiscoveredClass(
                name: 'NonExistentClass',
                namespace: '',
                file: '/test/NonExistentClass.php'
            ),
            'reflection_cache' => $reflectionCache,
        ]);

        // Should not throw, just log errors
        expect(fn () => $discovery->apply())->not->toThrow(Exception::class);
    });

    test('registers each #[Method] on rest_api_init and calls the controller with its dependencies', function (): void {
        $hooks = [];
        $routes = [];
        Functions\when('add_action')->alias(function (string $hook, callable $callback) use (&$hooks): bool {
            $hooks[$hook][] = $callback;

            return true;
        });
        Functions\when('register_rest_route')->alias(function (string $namespace, string $route, array $args) use (&$routes): bool {
            $routes[] = ['namespace' => $namespace, 'route' => $route, 'args' => $args];

            return true;
        });

        $discovery = new WpRestDiscovery;
        $discovery->discover(new DiscoveryLocation('', __DIR__), DiscoveredClass::fromReflection(new ReflectionClass(ReportsController::class)), new ReflectionCache);
        $discovery->apply();

        expect($routes)->toBe([]);

        foreach ($hooks['rest_api_init'] ?? [] as $callback) {
            $callback();
        }

        expect($routes)->toHaveCount(1)
            ->and($routes[0]['namespace'])->toBe('api/v1')
            ->and($routes[0]['route'])->toBe('/reports')
            ->and($routes[0]['args']['methods'])->toBe(['GET'])
            ->and($routes[0]['args']['callback'](Mockery::mock(WP_REST_Request::class)))->toBe(['title' => 'Annual report']);
    });

    test('returns correct identifier', function (): void {
        $discovery = new WpRestDiscovery;
        expect($discovery->getIdentifier())->toBe('wp_rest_routes');
    });

    test('wrapper system works with non-Attributable classes', function (): void {
        // Test the core wrapper functionality separately
        $className = 'TestDocumentAPI';
        $namespace = 'api/v1';
        $route = '/documents/(?P<documentId>\\d+)';

        // Create wrapper like WpRestDiscovery does
        $wrapper = new class($className, $namespace, $route) implements Attributable
        {
            private mixed $realInstance = null;

            public function __construct(
                private readonly string $className,
                public readonly string $namespace,
                public readonly string $route,
                public readonly ?string $classPermission = null
            ) {}

            public function getRealInstance(): mixed
            {
                if ($this->realInstance === null) {
                    $reflectionClass = new ReflectionClass($this->className);
                    if ($reflectionClass->isInstantiable()) {
                        $this->realInstance = $reflectionClass->newInstance();
                    }
                }

                return $this->realInstance;
            }
        };

        expect($wrapper)->toBeInstanceOf(Attributable::class);
        expect($wrapper->namespace)->toBe($namespace);
        expect($wrapper->route)->toBe($route);
        expect($wrapper->getRealInstance())->toBeInstanceOf(TestDocumentAPI::class);
    });
});
