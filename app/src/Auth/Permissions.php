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
        'imports.read', 'imports.write', 'invoices.read', 'invoices.write', 'payments.approve',
        'contacts.read', 'contacts.write', 'contributions.read', 'contributions.write',
    ];

    /** Zulaessige Rollen (Whitelist fuer Eingaben und Benutzerverwaltung). */
    public const ROLES = ['admin', 'vorstand', 'kassenwart', 'schriftfuehrer', 'kassenpruefer'];

    private const BY_ROLE = [
        'admin' => self::ALL,
        'vorstand' => [],
        'kassenwart' => ['bookings.read', 'bookings.write', 'members.read', 'reports.read',
            'imports.read', 'imports.write', 'invoices.read', 'invoices.write', 'payments.approve',
            'contacts.read', 'contacts.write', 'contributions.read', 'contributions.write'],
        'schriftfuehrer' => ['members.read', 'members.write', 'bookings.read'],
        'kassenpruefer' => ['bookings.read', 'members.read', 'reports.read',
            'imports.read', 'invoices.read', 'contacts.read', 'contributions.read'],
    ];

    /** Admin nur bei neuer Vorstand-Zuweisung vergeben, niemals beim Lesen nachtraeglich ergaenzen.
     * @param string[] $roles
     * @param string[] $previous
     * @return string[]
     */
    public static function onAssignment(array $roles, array $previous = []): array
    {
        if (in_array('vorstand', $roles, true) && !in_array('vorstand', $previous, true)) {
            $roles[] = 'admin';
        }
        $roles = array_values(array_unique($roles));
        sort($roles, SORT_STRING);
        return $roles;
    }

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
