<?php

declare(strict_types=1);

use Pollora\Support\WpGlobals;
use Pollora\WpRest\Infrastructure\Providers\WpRestAttributeServiceProvider;

/**
 * The WpRest module becomes pollora/wp-rest, which runs without Laravel's
 * application. Only its service provider may reach for Laravel; the rest uses
 * the attributes, discovery, PSR contracts and WordPress.
 */
arch('the WpRest module stays free of the Laravel application')
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
    ->ignoring([WpRestAttributeServiceProvider::class, 'WP_REST_Request', 'WP_Error']);
