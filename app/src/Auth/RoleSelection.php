<?php
declare(strict_types=1);

namespace Vereinskiste\Auth;

use Vereinskiste\Ledger\ValidationError;

/** Gemeinsame Eingabepruefung fuer Formular und Repository. */
final class RoleSelection
{
    /** @param array<mixed> $roles @return string[] */
    public static function read(array $roles): array
    {
        foreach ($roles as $role) {
            if (!is_string($role) || !in_array($role, Permissions::MANUAL_ROLES, true)) {
                throw new ValidationError(['roles' => 'validation.user.roles.unknown']);
            }
        }
        $roles = array_values(array_unique($roles));
        sort($roles, SORT_STRING);
        return $roles;
    }
}
