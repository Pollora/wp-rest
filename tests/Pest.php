<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;

require_once __DIR__.'/Stubs/wordpress.php';

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| No framework to boot. WordPress functions are stubbed with Brain Monkey,
| and Mockery's expectations count as assertions.
|
*/

pest()->beforeEach(function (): void {
    Monkey\setUp();
    Functions\stubs([
        '__' => static fn (string $text): string => $text,
        // A route without a permission tells WordPress it is doing it wrong.
        '_doing_it_wrong' => static fn (): null => null,
        'add_action' => true,
        // Nobody hooks the legacy filter name: the value passes through
        'apply_filters_deprecated' => static fn (string $hook, array $args): mixed => $args[0],
        'register_rest_route' => true,
        'wp_is_serving_rest_request' => false,
    ]);
})->afterEach(function (): void {
    if ($container = Mockery::getContainer()) {
        $this->addToAssertionCount($container->mockery_getExpectationCount());
    }

    Monkey\tearDown();
})->in('Unit');
