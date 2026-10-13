<?php
declare(strict_types=1);
namespace Vereinskiste\Dashboard;

final class Catalog
{
    public const CARDS = [
        'members' => ['label' => 'Mitglieder', 'permission' => 'members.read', 'url' => '/members?state=all', 'icon' => 'members'],
        'applications' => ['label' => 'Offene Mitgliedsanträge', 'permission' => 'members.write', 'url' => '/members/applications', 'icon' => 'members'],
        'finance' => ['label' => 'Finanzen', 'permission' => 'reports.read', 'url' => '/reports/summary', 'icon' => 'reports'],
        'invoices' => ['label' => 'Rechnungsentwürfe', 'permission' => 'invoices.read', 'url' => '/invoices', 'icon' => 'bookings'],
        'contacts' => ['label' => 'Aktive Kontakte', 'permission' => 'contacts.read', 'url' => '/contacts', 'icon' => 'contacts'],
        'operations' => ['label' => 'E-Mail-Versand', 'permission' => 'settings.manage', 'url' => '/settings/email', 'icon' => 'mail'],
    ];
    public const SHORTCUTS = [
        'member-add' => ['label' => 'Mitglied hinzufügen', 'permission' => 'members.write', 'url' => '/members/new', 'icon' => 'members'],
        'member-invite' => ['label' => 'Mitglied einladen', 'permission' => 'members.write', 'url' => '/members/applications#invite-title', 'icon' => 'mail'],
        'booking-add' => ['label' => 'Buchung erfassen', 'permission' => 'bookings.write', 'url' => '/bookings/new', 'icon' => 'bookings'],
        'invoice-import' => ['label' => 'Rechnung importieren', 'permission' => 'invoices.write', 'url' => '/invoices/import', 'icon' => 'imports'],
        'bank-import' => ['label' => 'Kontoauszug importieren', 'permission' => 'imports.write', 'url' => '/imports/bank/new', 'icon' => 'imports'],
        'contact-add' => ['label' => 'Kontakt hinzufügen', 'permission' => 'contacts.write', 'url' => '/contacts/new', 'icon' => 'contacts'],
        'reports' => ['label' => 'Auswertungen öffnen', 'permission' => 'reports.read', 'url' => '/reports/dashboard', 'icon' => 'reports'],
    ];

    public static function allowed(array $catalog, callable $can): array
    {
        return array_filter($catalog, static fn(array $item): bool => $can($item['permission']));
    }
}
