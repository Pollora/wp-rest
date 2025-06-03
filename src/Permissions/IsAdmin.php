<?php

declare(strict_types=1);

namespace Pollora\WpRest\Permissions;

use Pollora\Attributes\WpRestRoute\Permission;
use WP_Error;
use WP_REST_Request;

class IsAdmin implements Permission
{
    /**
     * Checks if the current user has admin permissions.
     *
     * @param  WP_REST_Request  $request  The REST request instance.
     * @return bool|\WP_Error True if the user is an admin, WP_Error otherwise.
     *
     * @throws \Exception
     */
    public function allow(WP_REST_Request $request): bool|WP_Error
    {
        return current_user_can('manage_options') ?: new WP_Error(
            'rest_forbidden',
            __('You do not have permission to access this endpoint.'),
            ['status' => 403]
        );
    }
}
