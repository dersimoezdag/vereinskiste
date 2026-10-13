<?php
declare(strict_types=1);
namespace Vereinskiste\Dashboard;

final class Catalog
{
    public const CARDS = [
        'members' => ['label' => 'home.label.mitglieder', 'permission' => 'members.read', 'url' => '/members?state=all', 'icon' => 'members'],
        'applications' => ['label' => 'home.label.offene_mitgliedsantraege', 'permission' => 'members.write', 'url' => '/members/applications', 'icon' => 'members'],
        'finance' => ['label' => 'home.label.finanzen', 'permission' => 'reports.read', 'url' => '/reports/dashboard', 'icon' => 'reports'],
        'invoices' => ['label' => 'home.label.rechnungsentwuerfe', 'permission' => 'invoices.read', 'url' => '/invoices', 'icon' => 'bookings'],
        'contacts' => ['label' => 'home.label.aktive_kontakte', 'permission' => 'contacts.read', 'url' => '/contacts', 'icon' => 'contacts'],
        'operations' => ['label' => 'home.label.e_mail_versand', 'permission' => 'settings.manage', 'url' => '/settings/email', 'icon' => 'mail'],
    ];
    public const SHORTCUTS = [
        'member-add' => ['label' => 'home.label.mitglied_hinzufuegen', 'permission' => 'members.write', 'url' => '/members/new', 'icon' => 'members'],
        'member-invite' => ['label' => 'home.label.mitglied_einladen', 'permission' => 'members.write', 'url' => '/members/applications#invite-title', 'icon' => 'mail'],
        'booking-add' => ['label' => 'home.label.buchung_erfassen', 'permission' => 'bookings.write', 'url' => '/bookings/new', 'icon' => 'bookings'],
        'invoice-import' => ['label' => 'home.label.rechnung_importieren', 'permission' => 'invoices.write', 'url' => '/invoices/import', 'icon' => 'imports'],
        'bank-import' => ['label' => 'home.label.kontoauszug_importieren', 'permission' => 'imports.write', 'url' => '/imports/bank/new', 'icon' => 'imports'],
        'contact-add' => ['label' => 'home.label.kontakt_hinzufuegen', 'permission' => 'contacts.write', 'url' => '/contacts/new', 'icon' => 'contacts'],
        'reports' => ['label' => 'home.label.auswertungen_oeffnen', 'permission' => 'reports.read', 'url' => '/reports/dashboard', 'icon' => 'reports'],
    ];

    public static function allowed(array $catalog, callable $can): array
    {
        return array_filter($catalog, static fn(array $item): bool => $can($item['permission']));
    }
}
