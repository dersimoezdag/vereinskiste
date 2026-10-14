<?php
declare(strict_types=1);

namespace Vereinskiste\Auth;

final class Permissions
{
    public const ALL = [
        'functions.manage',
        'documents.read', 'documents.write', 'documents.confidential',
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
    public const ROLES = ['admin', 'board', 'treasurer', 'secretary', 'auditor'];

    public const MANUAL_ROLES = ['admin', 'treasurer', 'secretary', 'auditor'];

    private const BY_ROLE = [
        'admin' => self::ALL,
        'board' => ['documents.read', 'documents.write', 'documents.confidential', 'members.read', 'members.write', 'bookings.read', 'bookings.write', 'reports.read', 'imports.read', 'imports.write', 'invoices.read', 'invoices.write', 'payments.approve', 'contacts.read', 'contacts.write', 'contributions.read', 'contributions.write'],
        'treasurer' => ['documents.read', 'bookings.read', 'bookings.write', 'members.read', 'reports.read',
            'imports.read', 'imports.write', 'invoices.read', 'invoices.write', 'payments.approve',
            'contacts.read', 'contacts.write', 'contributions.read', 'contributions.write'],
        'secretary' => ['documents.read', 'documents.write', 'documents.confidential', 'members.read', 'members.write', 'bookings.read'],
        'auditor' => ['documents.read', 'bookings.read', 'members.read', 'reports.read',
            'imports.read', 'invoices.read', 'contacts.read', 'contributions.read'],
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
