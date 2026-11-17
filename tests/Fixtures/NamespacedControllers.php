<?php

declare(strict_types=1);

namespace Fixtures\Mutation;

use Deprecated;
use Pollora\Attributes\WpRestRoute;
use Pollora\Attributes\WpRestRoute\Method;
use Pollora\Attributes\WpRestRoute\ProbeAttribute;

#[WpRestRoute('ns/v1', '/namespaced')]
final class NamespacedController
{
    #[Method('GET')]
    public function index(): array
    {
        return [];
    }
}

#[WpRestRoute('ns/v1', '/ordered')]
final class OrderedAttributesController
{
    // A foreign attribute comes first: it is skipped, the next ones still count
    #[Deprecated]
    #[ProbeAttribute]
    #[Method('GET')]
    public function index(): array
    {
        return [];
    }
}

#[WpRestRoute('ns/v1', '/first')]
final class FirstSharedController
{
    #[ProbeAttribute]
    public function probe(): void {}
}

#[WpRestRoute('ns/v1', '/second')]
final class SecondSharedController
{
    #[ProbeAttribute]
    public function probe(): void {}
}

#[WpRestRoute('other/v1', '/first')]
final class ThirdSharedController
{
    #[ProbeAttribute]
    public function probe(): void {}
}

#[WpRestRoute('ns/v1', '/first')]
final class FourthSharedController
{
    #[ProbeAttribute]
    public function probe(): void {}
}
