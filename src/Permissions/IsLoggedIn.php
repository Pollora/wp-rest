<?php

declare(strict_types=1);

namespace Pollora\WpRest\Permissions;

use Pollora\Attributes\WpRestRoute\Permission;
use WP_Error;
use WP_REST_Request;

class IsLoggedIn implements Permission
{
    /**
     * Checks if the current user is logged in.
     *
     * @param  WP_REST_Request  $request  The REST request instance.
     * @return bool|\WP_Error True if the user is logged in, WP_Error otherwise.
     *
     * @throws \Exception
     */
    public function allow(WP_REST_Request $request): bool|WP_Error
    {
        return is_user_logged_in() ?: new WP_Error(
            'rest_forbidden',
            __('You do not have permission to access this endpoint.'),
            ['status' => 403]
        );
    }
}
