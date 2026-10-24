<?php

declare(strict_types=1);

namespace Pollora\WpRest\Permissions;

use Pollora\Attributes\WpRestRoute\Permission;
use WP_REST_Request;

/**
 * Lets anyone call the route, logged in or not.
 *
 * A route without a permission is public too, but says nothing about it: a
 * reader cannot tell a deliberate public endpoint from a forgotten check.
 * Everyone makes the choice visible in the code, and keeps the route public
 * once routes without a permission are refused.
 */
class Everyone implements Permission
{
    public function allow(WP_REST_Request $request): bool
    {
        return true;
    }
}
