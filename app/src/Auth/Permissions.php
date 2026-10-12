<?php
declare(strict_types=1);

namespace Vereinskiste\Auth;

final class Permissions
{
    public const ALL = [
        'bookings.read',
        'bookings.write',
        'members.read',
        'members.write',
        'reports.read',
        'users.manage',
        'settings.manage',
    ];

    /** Zulaessige Rollen (Whitelist fuer Eingaben und Benutzerverwaltung). */
    public const ROLES = ['admin', 'vorstand', 'kassenwart', 'schriftfuehrer', 'kassenpruefer'];

    private const BY_ROLE = [
        'admin' => self::ALL,
        'vorstand' => self::ALL,
        'kassenwart' => ['bookings.read', 'bookings.write', 'members.read', 'reports.read'],
        'schriftfuehrer' => ['members.read', 'members.write', 'bookings.read'],
        'kassenpruefer' => ['bookings.read', 'members.read', 'reports.read'],
    ];

    /**
     * Vereinigung der Rechte aller Rollen; unbekannte Rollen geben nichts.
     *
     * @param string[] $roles
     * @return string[]
     */
    public static function for(array $roles): array
    {
        $rights = [];
        foreach ($roles as $role) {
            foreach (self::BY_ROLE[$role] ?? [] as $right) {
                $rights[$right] = true;
            }
        }
        return array_keys($rights);
    }
}
