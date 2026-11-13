<?php

declare(strict_types=1);

namespace Pollora\Attributes\WpRestRoute;

use Attribute;
use Pollora\Attributes\Attributable;
use Pollora\Attributes\Contracts\HandlesAttributes;
use ReflectionClass;
use ReflectionMethod;

/*
| Test attributes living in the WpRestRoute namespace, which is what the
| discovery looks at on a method. They are loaded by the tests that use them.
*/

#[Attribute(Attribute::TARGET_METHOD)]
final class ProbeAttribute implements HandlesAttributes
{
    /** @var list<array{0: mixed, 1: Attributable, 2: ReflectionClass|ReflectionMethod, 3: object}> */
    public static array $handled = [];

    public function handle($container, Attributable $instance, ReflectionClass|ReflectionMethod $context, object $attribute): void
    {
        self::$handled[] = [$container, $instance, $context, $attribute];
    }
}

#[Attribute(Attribute::TARGET_METHOD)]
final class ExplodingAttribute
{
    public function __construct()
    {
        throw new \RuntimeException('attribute cannot be built');
    }
}

#[Attribute(Attribute::TARGET_METHOD)]
final class PlainAttribute {}
