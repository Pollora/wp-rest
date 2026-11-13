<?php

declare(strict_types=1);

use Pollora\Support\WpGlobals;

beforeEach(function (): void {
    $this->essentials = WpGlobals::getEssentialGlobals();
    $this->touched = ['wpr_test_a' => $GLOBALS['wpr_test_a'] ?? null, 'wpr_test_b' => $GLOBALS['wpr_test_b'] ?? null];
});

afterEach(function (): void {
    WpGlobals::setEssentialGlobals($this->essentials);

    foreach ($this->touched as $name => $value) {
        unset($GLOBALS[$name]);
    }
});

it('puts back the captured globals when the callback runs', function (): void {
    $GLOBALS['wpr_test_a'] = 'captured';

    $wrapped = WpGlobals::wrap(fn (): mixed => $GLOBALS['wpr_test_a'], ['wpr_test_a']);
    $GLOBALS['wpr_test_a'] = 'replaced elsewhere';

    expect($wrapped())->toBe('captured');
});

it('forwards the arguments and returns the result of the callback', function (): void {
    $wrapped = WpGlobals::wrap(fn (int $a, int $b): int => $a + $b, []);

    expect($wrapped(2, 3))->toBe(5);
});

it('captures nothing it was not asked to', function (): void {
    $GLOBALS['wpr_test_a'] = 'a';
    $GLOBALS['wpr_test_b'] = 'b';

    $wrapped = WpGlobals::wrap(fn (): mixed => $GLOBALS['wpr_test_b'] ?? null, ['wpr_test_a']);
    $GLOBALS['wpr_test_b'] = 'changed';

    expect($wrapped())->toBe('changed');
});

it('captures the essential globals by default', function (): void {
    WpGlobals::setEssentialGlobals(['wpr_test_a']);
    $GLOBALS['wpr_test_a'] = 'default list';

    $wrapped = WpGlobals::wrap(fn (): mixed => $GLOBALS['wpr_test_a']);
    $GLOBALS['wpr_test_a'] = 'replaced';

    expect($wrapped())->toBe('default list');
});

it('adds an essential global once', function (): void {
    WpGlobals::addEssentialGlobal('wpr_test_a');
    WpGlobals::addEssentialGlobal('wpr_test_a');

    expect(array_count_values(WpGlobals::getEssentialGlobals())['wpr_test_a'])->toBe(1);
});

it('lists the WordPress globals handled by default', function (): void {
    expect(WpGlobals::getEssentialGlobals())->toContain('current_user', 'wpdb', 'wp_query');
});

it('replaces the whole list', function (): void {
    WpGlobals::setEssentialGlobals(['only_one']);

    expect(WpGlobals::getEssentialGlobals())->toBe(['only_one']);
});
