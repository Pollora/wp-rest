<?php

declare(strict_types=1);

namespace Pollora\WpRest\Infrastructure\Services;

use Pollora\Attributes\Attributable;
use ReflectionClass;

/**
 * Wrapper class to make any class compatible with Attributable interface.
 * 
 * This class acts as a bridge between regular classes that use WpRestRoute attributes
 * and the Attributable interface required by the Method attribute handlers.
 */
final readonly class WpRestAttributableWrapper implements Attributable
{
    private mixed $realInstance;

    public function __construct(
        private string $className,
        public string $namespace,
        public string $route,
        public ?string $classPermission = null
    ) {
        $this->realInstance = $this->createRealInstance();
    }

    /**
     * Get the real instance of the wrapped class.
     */
    public function getRealInstance(): mixed
    {
        return $this->realInstance;
    }

    /**
     * Create an instance of the real class.
     */
    private function createRealInstance(): mixed
    {
        try {
            $reflectionClass = new ReflectionClass($this->className);
            
            if ($reflectionClass->isInstantiable()) {
                return $reflectionClass->newInstance();
            }
        } catch (\Throwable $e) {
            error_log("Failed to create instance of {$this->className}: " . $e->getMessage());
        }

        return null;
    }
}