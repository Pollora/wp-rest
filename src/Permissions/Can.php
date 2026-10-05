<?php

declare(strict_types=1);

namespace Pollora\WpRest\Permissions;

use BackedEnum;
use Pollora\Attributes\WpRestRoute\Permission;
use WP_Error;
use WP_REST_Request;

/**
 * Allows the current user when they have a capability.
 *
 *     #[WpRestRoute('app/v1', 'events', permissionCallback: new Can(EventCap::ExportAttendees))]
 *     #[Method('PUT', permissionCallback: new Can('edit_post', parameter: 'id'))]
 *
 * With `parameter`, the value of that request parameter is passed with the
 * capability, for a meta capability such as `edit_post` on the post being edited.
 */
final readonly class Can implements Permission
{
    /**
     * @param  string|BackedEnum  $capability  A capability, or a case of a #[CapabilitySet] enum
     * @param  string|null  $parameter  A request parameter whose value is passed with the capability
     */
    public function __construct(
        public string|BackedEnum $capability,
        public ?string $parameter = null,
    ) {}

    public function allow(WP_REST_Request $request): bool|WP_Error
    {
        $capability = $this->capability instanceof BackedEnum ? (string) $this->capability->value : $this->capability;
        $arguments = $this->parameter === null ? [] : [$request->get_param($this->parameter)];

        return current_user_can($capability, ...$arguments) ?: new WP_Error(
            'rest_forbidden',
            __('You do not have permission to access this endpoint.'),
            ['status' => rest_authorization_required_code()]
        );
    }
}
