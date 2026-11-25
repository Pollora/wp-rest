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
 * The request is refused (403/401) when that value is absent or is not a positive integer.
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
        $arguments = [];

        if ($this->parameter !== null) {
            $id = $this->positiveInteger($request->get_param($this->parameter));

            if ($id === null) {
                return $this->forbidden();
            }

            $arguments = [$id];
        }

        return current_user_can($capability, ...$arguments) ?: $this->forbidden();
    }

    /**
     * The value as a positive integer, or null when it is absent or is anything else
     * (a meta capability given a null id would fall back to the global post).
     */
    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function forbidden(): WP_Error
    {
        return new WP_Error(
            'rest_forbidden',
            __('You do not have permission to access this endpoint.'),
            ['status' => rest_authorization_required_code()]
        );
    }
}
