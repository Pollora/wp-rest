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
        'add_action' => true,
        'register_rest_route' => true,
    ]);
})->afterEach(function (): void {
    if ($container = Mockery::getContainer()) {
        $this->addToAssertionCount($container->mockery_getExpectationCount());
    }

    Monkey\tearDown();
})->in('Unit');
