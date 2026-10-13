<?php

declare(strict_types=1);

use Pollora\Support\WpGlobals;

/**
 * pollora/wp-rest runs without Laravel's application: it uses the attributes,
 * discovery, PSR contracts and WordPress, nothing else. The Laravel service
 * provider lives in the framework.
 */
arch('wp-rest stays free of the Laravel application')
    ->expect(['Pollora\WpRest', WpGlobals::class])
    ->toOnlyUse([
        'Pollora\WpRest',
        'Pollora\Attributes',
        'Pollora\Discovery',
        WpGlobals::class,
        'Psr\Container',
        'Psr\Log',
        'Spatie\StructureDiscoverer',
        // WordPress
        '__',
        'add_action',
        'current_user_can',
        'is_user_logged_in',
        'register_rest_route',
        'rest_authorization_required_code',
    ])
    ->ignoring(['WP_REST_Request', 'WP_Error']);
