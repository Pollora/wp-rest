<?php

declare(strict_types=1);

namespace Pollora\Support;

/**
 * Class WpGlobals
 *
 * Utility class to manage WordPress globals in different contexts.
 */
class WpGlobals
{
    /**
     * List of essential WordPress globals that are commonly needed.
     *
     * @var array<int, string>
     */
    private static array $essentialGlobals = [
        'current_user',
        'wpdb',
        'wp_roles',
        'wp',
        'wp_query',
        'post',
        'pagenow',
        'hook_suffix',
        'wp_filter',
        'wp_actions',
        'wp_current_filter',
    ];

    /**
     * Wraps a callback function with WordPress globals.
     *
     * @param  callable  $callback  The function to wrap
     * @param  array|null  $globals  Optional specific list of globals to preserve
     * @return callable The wrapped function with access to WordPress globals
     */
    public static function wrap(callable $callback, ?array $globals = null): callable
    {
        // If $globals is null, use the default list
        $globalKeys = $globals ?? self::$essentialGlobals;

        // Capture the global variables
        $capturedGlobals = array_intersect_key($GLOBALS, array_flip($globalKeys));

        return static function (...$args) use ($callback, $capturedGlobals) {
            // Reinject the globals into the context
            foreach ($capturedGlobals as $key => $value) {
                $GLOBALS[$key] = $value;
            }

            // Execute the callback
            return $callback(...$args);
        };
    }

    /**
     * Adds a global to the list of essential globals.
     *
     * @param  string  $globalName  Name of the global variable to add
     */
    public static function addEssentialGlobal(string $globalName): void
    {
        if (! in_array($globalName, self::$essentialGlobals)) {
            self::$essentialGlobals[] = $globalName;
        }
    }

    /**
     * Sets multiple essential globals at once.
     *
     * @param  array  $globalNames  An array of global variable names
     */
    public static function setEssentialGlobals(array $globalNames): void
    {
        self::$essentialGlobals = $globalNames;
    }

    /**
     * Gets the current list of essential globals.
     *
     * @return array The list of essential global variable names
     */
    public static function getEssentialGlobals(): array
    {
        return self::$essentialGlobals;
    }
}
