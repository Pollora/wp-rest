<?php

declare(strict_types=1);

namespace Pollora\WpRest\Infrastructure\Services;

use Pollora\Attributes\Attributable;
use Pollora\Attributes\WpRestRoute\Permission;

/**
 * Wrapper class to make any class compatible with Attributable interface.
 *
 * This class acts as a bridge between regular classes that use WpRestRoute attributes
 * and the Attributable interface required by the Method attribute handlers. It carries
 * the controller instance the discovery resolved, dependencies included.
 */
final readonly class WpRestAttributableWrapper implements Attributable
{
    public function __construct(
        private object $realInstance,
        public string $namespace,
        public string $route,
        public string|Permission|null $classPermission = null,
    ) {}

    /**
     * Get the real instance of the wrapped class.
     */
    public function getRealInstance(): object
    {
        return $this->realInstance;
    }
}
