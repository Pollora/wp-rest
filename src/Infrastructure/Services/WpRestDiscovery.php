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
     * Discovers methods with WpRestRoute attributes and collects them for registration.
     * Only processes public methods that have the WpRestRoute attribute.
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

        try {
            // Use reflection to examine methods for WpRestRoute attributes
            $reflectionClass = new ReflectionClass($structure->namespace.'\\'.$structure->name);

            foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $restRouteAttributes = $method->getAttributes(WpRestRoute::class);

                if (empty($restRouteAttributes)) {
                    continue;
                }

                foreach ($restRouteAttributes as $restRouteAttribute) {
                    // Collect the method for registration
                    $this->getItems()->add($location, [
                        'class' => $structure->namespace.'\\'.$structure->name,
                        'method' => $method->getName(),
                        'attribute' => $restRouteAttribute,
                        'reflection_method' => $method,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Skip classes that can't be reflected
            // This might happen for classes with missing dependencies
            return;
        }
    }

    /**
     * {@inheritDoc}
     *
     * Applies discovered WpRestRoute methods by registering them as REST API endpoints.
     * Each discovered method is registered using WordPress REST API registration functions.
     */
    public function apply(): void
    {
        foreach ($this->getItems() as $discoveredItem) {
            [
                'class' => $className,
                'method' => $methodName,
                'attribute' => $restRouteAttribute,
                'reflection_method' => $reflectionMethod
            ] = $discoveredItem;

            try {
                /** @var WpRestRoute $restRoute */
                $restRoute = $restRouteAttribute->newInstance();

                // Register the REST route using WordPress functions
                add_action('rest_api_init', function () use ($restRoute, $className, $methodName) {
                    register_rest_route(
                        namespace: $restRoute->namespace,
                        route: $restRoute->route,
                        args: [
                            'methods' => $restRoute->methods,
                            'callback' => [$className, $methodName],
                            'permission_callback' => $restRoute->permissionCallback ?? '__return_true',
                            'args' => $restRoute->args ?? [],
                        ]
                    );
                });
            } catch (\Throwable $e) {
                // Log the error but continue with other REST routes
                // In a production environment, you might want to use a proper logger
                error_log("Failed to register WP REST route from method {$className}::{$methodName}: " . $e->getMessage());
            }
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
