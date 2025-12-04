<?php

declare(strict_types=1);

namespace Pollora\WpRest\Infrastructure\Services;

use Pollora\Attributes\WpRestRoute;
use Pollora\Discovery\Domain\Contracts\DiscoveryInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;
use Pollora\Discovery\Domain\Services\HasInstancePool;
use Pollora\Discovery\Domain\Services\IsDiscovery;
use ReflectionClass;
use ReflectionMethod;
use Spatie\StructureDiscoverer\Data\DiscoveredStructure;

/**
 * WP REST Discovery
 *
 * Discovers classes decorated with WpRestRoute attributes and registers them
 * as WordPress REST API endpoints. This discovery class scans for classes
 * that have the #[WpRestRoute] attribute and processes their methods for registration.
 */
final class WpRestDiscovery implements DiscoveryInterface
{
    use HasInstancePool, IsDiscovery;

    /**
     * Cache for wrapper instances to avoid recreating them
     */
    private array $wrapperCache = [];

    /**
     * {@inheritDoc}
     *
     * Discovers classes with WpRestRoute attributes and collects them for registration.
     * Only processes classes that have the WpRestRoute attribute.
     */
    public function discover(DiscoveryLocationInterface $location, DiscoveredStructure $structure): void
    {
        // Only process classes
        if (! $structure instanceof \Spatie\StructureDiscoverer\Data\DiscoveredClass) {
            return;
        }

        // Skip abstract classes
        if ($structure->isAbstract) {
            return;
        }

        // Check if class has WpRestRoute attribute
        $wpRestRouteAttribute = $this->findWpRestRouteAttribute($structure->attributes);
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
                $this->processWpRestRoute($className);
            } catch (\Throwable $e) {
                // Log the error but continue with other REST routes
                error_log("Failed to register WP REST route from class {$className}: ".$e->getMessage());
            }
        }
    }

    /**
     * Find WpRestRoute attribute in the given attributes array.
     *
     * @param  array  $attributes  The attributes to search through
     * @return object|null The WpRestRoute attribute or null if not found
     */
    private function findWpRestRouteAttribute(array $attributes): ?object
    {
        foreach ($attributes as $attribute) {
            if ($attribute->class === WpRestRoute::class) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * Process a complete WP REST route configuration from its class and attributes.
     *
     * @param  string  $className  The fully qualified class name
     */
    private function processWpRestRoute(string $className): void
    {
        try {
            $reflectionClass = new ReflectionClass($className);
            $wpRestRouteAttributes = $reflectionClass->getAttributes(WpRestRoute::class);

            if ($wpRestRouteAttributes === []) {
                return;
            }

            /** @var WpRestRoute $wpRestRoute */
            $wpRestRoute = $wpRestRouteAttributes[0]->newInstance();

            // Create wrapper once for the class (use cache to avoid recreating)
            $wrapperKey = md5($className.$wpRestRoute->namespace.$wpRestRoute->route);

            if (! isset($this->wrapperCache[$wrapperKey])) {
                $this->wrapperCache[$wrapperKey] = new WpRestAttributableWrapper(
                    $className,
                    $wpRestRoute->namespace,
                    $wpRestRoute->route,
                    $wpRestRoute->permissionCallback
                );
            }

            $attributableWrapper = $this->wrapperCache[$wrapperKey];

            // Process all method-level attributes
            $this->processMethodLevelAttributes($reflectionClass, $attributableWrapper);

        } catch (\ReflectionException $e) {
            error_log("Failed to process WP REST route for class {$className}: ".$e->getMessage());
        }
    }

    /**
     * Process method-level attributes to register REST endpoints.
     *
     * @param  ReflectionClass  $reflectionClass  The reflection class
     * @param  WpRestAttributableWrapper  $attributableWrapper  The wrapper instance
     */
    private function processMethodLevelAttributes(ReflectionClass $reflectionClass, WpRestAttributableWrapper $attributableWrapper): void
    {
        try {
            $methods = $reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                $this->processMethodAttributes($method, $attributableWrapper);
            }
        } catch (\ReflectionException $e) {
            error_log("Failed to process method-level attributes for {$reflectionClass->getName()}: ".$e->getMessage());
        }
    }

    /**
     * Process all attributes for a single method.
     *
     * @param  ReflectionMethod  $method  The method to process
     * @param  WpRestAttributableWrapper  $attributableWrapper  The wrapper instance
     */
    private function processMethodAttributes(ReflectionMethod $method, WpRestAttributableWrapper $attributableWrapper): void
    {
        foreach ($method->getAttributes() as $attribute) {
            // Process attributes in the WpRestRoute namespace
            if (! str_contains($attribute->getName(), 'Pollora\\Attributes\\WpRestRoute\\')) {
                continue;
            }

            $this->processMethodAttribute($method, $attribute, $attributableWrapper);
        }
    }

    /**
     * Process a single method-level attribute for REST route registration.
     *
     * @param  ReflectionMethod  $method  The method with the attribute
     * @param  \ReflectionAttribute  $attribute  The attribute to process
     * @param  WpRestAttributableWrapper  $attributableWrapper  The wrapper instance
     */
    private function processMethodAttribute(
        ReflectionMethod $method,
        \ReflectionAttribute $attribute,
        WpRestAttributableWrapper $attributableWrapper
    ): void {
        try {
            $attributeInstance = $attribute->newInstance();

            // Check if the attribute has a handle method and call it
            if (! method_exists($attributeInstance, 'handle')) {
                return;
            }

            // Let the attribute handle the registration
            add_action('rest_api_init', function () use ($attributeInstance, $attributableWrapper, $method): void {
                $attributeInstance->handle(app(), $attributableWrapper, $method, $attributeInstance);
            });
        } catch (\Throwable $e) {
            $className = $method->getDeclaringClass()->getName();
            error_log("Failed to process method attribute for {$className}::{$method->getName()}: ".$e->getMessage());
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
