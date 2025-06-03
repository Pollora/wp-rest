<?php

declare(strict_types=1);

namespace Pollora\WpRest;

use Pollora\Attributes\Attributable;
use Pollora\Attributes\AttributableHookTrait;

/**
 * Base class for REST API controllers using WpRestRoute attributes.
 *
 * This class implements the Attributable interface and ensures that
 * all REST routes are registered at the appropriate time in the WordPress
 * lifecycle using the rest_api_init hook.
 */
abstract class AbstractWpRestRoute implements Attributable
{
    use AttributableHookTrait;

    /**
     * Namespace for the REST API route.
     */
    public string $namespace;

    /**
     * Route pattern for the REST API.
     */
    public string $route;

    /**
     * Permission callback class for the route.
     */
    public ?string $classPermission = null;

    /**
     * Specifies the WordPress hook on which to process attributes.
     *
     * For REST routes, we defer processing until the rest_api_init hook
     * to ensure that the WordPress environment is fully initialized,
     * including user context and request availability.
     *
     * @return string The WordPress hook name
     */
    public function getHook(): ?string
    {
        return 'rest_api_init';
    }
}
