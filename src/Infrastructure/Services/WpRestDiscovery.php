<?php

declare(strict_types=1);

namespace Pollora\WpRest\Infrastructure\Services;

use Pollora\Attributes\WpRestRoute;
use Pollora\Discovery\Domain\Contracts\DiscoveryInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;
use Pollora\Discovery\Domain\Services\IsDiscovery;
use ReflectionClass;
use ReflectionMethod;
use Spatie\StructureDiscoverer\Data\DiscoveredStructure;

/**
 * WP REST Discovery
 *
 * Discovers methods decorated with WpRestRoute attributes and registers them
 * as WordPress REST API endpoints. This discovery class scans for methods
 * that have the #[WpRestRoute] attribute and processes them for registration.
 *
 * @package Pollora\WpRest\Infrastructure\Services
 */
final class WpRestDiscovery implements DiscoveryInterface
{
    use IsDiscovery;

    /**
     * {@inheritDoc}
     *
     * Discovers classes with WpRestRoute attributes and collects them for registration.
     * Only processes classes that have the WpRestRoute attribute.
     */
    public function discover(DiscoveryLocationInterface $location, DiscoveredStructure $structure): void
    {
        // Only process classes
        if (!$structure instanceof \Spatie\StructureDiscoverer\Data\DiscoveredClass) {
            return;
        }

        // Skip abstract classes
        if ($structure->isAbstract) {
            return;
        }

        // Check if class has WpRestRoute attribute
        $wpRestRouteAttribute = null;
        foreach ($structure->attributes as $attribute) {
            if ($attribute->class === WpRestRoute::class) {
                $wpRestRouteAttribute = $attribute;
                break;
            }
        }

        if ($wpRestRouteAttribute === null) {
            return;
        }

        // Collect the class for registration
        $this->getItems()->add($location, [
            'class' => $structure->namespace.'\\'.$structure->name,
            'attribute' => $wpRestRouteAttribute,
            'structure' => $structure,
        ]);
    }

    /**
     * {@inheritDoc}
     *
     * Applies discovered WpRestRoute classes by registering them as REST API endpoints.
     * Each discovered class is processed for method-level attributes.
     */
    public function apply(): void
    {
        foreach ($this->getItems() as $discoveredItem) {
            [
                'class' => $className,
                'attribute' => $restRouteAttribute,
                'structure' => $structure
            ] = $discoveredItem;

            try {
                // Process the complete WP REST route configuration
                $this->processWpRestRoute($className, $restRouteAttribute);
            } catch (\Throwable $e) {
                // Log the error but continue with other REST routes
                error_log("Failed to register WP REST route from class {$className}: " . $e->getMessage());
            }
        }
    }

    /**
     * Process a complete WP REST route configuration from its class and attributes.
     *
     * @param string $className The fully qualified class name
     * @param object $restRouteAttribute The Spatie DiscoveredAttribute instance
     * @return void
     */
    private function processWpRestRoute(string $className, object $restRouteAttribute): void
    {
        try {
            // Use reflection to get the WpRestRoute attribute instance
            $reflectionClass = new ReflectionClass($className);
            $wpRestRouteAttributes = $reflectionClass->getAttributes(WpRestRoute::class);

            if (empty($wpRestRouteAttributes)) {
                return;
            }

            // Get the WpRestRoute attribute instance
            /** @var WpRestRoute $wpRestRoute */
            $wpRestRoute = $wpRestRouteAttributes[0]->newInstance();

            // Process method-level attributes for HTTP methods
            $this->processMethodLevelAttributes($reflectionClass, $className, $wpRestRoute);

        } catch (\ReflectionException $e) {
            error_log("Failed to process WP REST route for class {$className}: " . $e->getMessage());
        }
    }

    /**
     * Process method-level attributes to register REST endpoints.
     *
     * @param ReflectionClass $reflectionClass The reflection class
     * @param string $className The class name
     * @param WpRestRoute $wpRestRoute The WP REST route configuration
     * @return void
     */
    private function processMethodLevelAttributes(ReflectionClass $reflectionClass, string $className, WpRestRoute $wpRestRoute): void
    {
        try {
            foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes() as $attribute) {
                    // Process attributes in the WpRestRoute namespace
                    if (str_contains($attribute->getName(), 'Pollora\\Attributes\\WpRestRoute\\')) {
                        $this->processMethodAttribute($className, $method, $attribute, $wpRestRoute);
                    }
                }
            }
        } catch (\ReflectionException $e) {
            error_log("Failed to process method-level attributes for {$className}: " . $e->getMessage());
        }
    }

    /**
     * Process a single method-level attribute for REST route registration.
     *
     * @param string $className The class name
     * @param ReflectionMethod $method The method with the attribute
     * @param \ReflectionAttribute $attribute The attribute to process
     * @param WpRestRoute $wpRestRoute The WP REST route configuration
     * @return void
     */
    private function processMethodAttribute(
        string $className,
        ReflectionMethod $method,
        \ReflectionAttribute $attribute,
        WpRestRoute $wpRestRoute
    ): void {
        try {
            $attributeInstance = $attribute->newInstance();

            // Check if the attribute has a handle method and call it
            if (method_exists($attributeInstance, 'handle')) {
                // Create an Attributable wrapper that contains the route configuration and the real class instance
                $attributableInstance = new class($className, $wpRestRoute->namespace, $wpRestRoute->route, $wpRestRoute->permissionCallback) implements \Pollora\Attributes\Attributable {
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
                            $reflectionClass = new \ReflectionClass($this->className);
                            if ($reflectionClass->isInstantiable()) {
                                $this->realInstance = $reflectionClass->newInstance();
                            }
                        }
                        return $this->realInstance;
                    }
                };

                // Let the attribute handle the registration
                add_action('rest_api_init', function () use ($attributeInstance, $attributableInstance, $method) {
                    $attributeInstance->handle(app(), $attributableInstance, $method, $attributeInstance);
                });
            }
        } catch (\Throwable $e) {
            error_log("Failed to process method attribute for {$className}::{$method->getName()}: " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getIdentifier(): string
    {
        return 'wp_rest_routes';
    }
}
