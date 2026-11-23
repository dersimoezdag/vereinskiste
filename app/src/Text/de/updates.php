<?php
declare(strict_types=1);
return [
    'settings.back_to_application' => 'Zurück zur Anwendung',
    'settings.section.system' => 'System',
    'settings.section.club' => 'Verein',
    'settings.section.email' => 'E-Mail',
    'settings.section.finance' => 'Finanzen',
    'settings.section.access' => 'Zugriff & Protokoll',

    'settings.manufacturer.title' => 'Hersteller & Entwicklung',
    'settings.manufacturer.description' => 'Entwicklung der Vereinskiste.',
    'settings.manufacturer.profile' => 'Forgejo-Profil',
    'settings.manufacturer.repository' => 'Quellcode auf Forgejo',

    'update.maintenance.running_title' => 'Wir aktualisieren die Vereinskiste',
    'update.maintenance.running_text' => 'Die Anwendung ist während der Aktualisierung kurzzeitig nicht verfügbar. Bitte versuche es in wenigen Minuten erneut.',
    'update.maintenance.admin_login' => 'Anmeldung für Administratoren',
    'settings.release.error.transport_unavailable' => 'Das Hosting erlaubt keine HTTPS-Abfrage: bitte die PHP-Erweiterung cURL oder allow_url_fopen aktivieren.',
    'settings.release.error.connection' => 'Forgejo (forgejo.lexframe.de) ist vom Hosting aus nicht erreichbar. Bitte DNS und ausgehende HTTPS-Verbindungen prüfen lassen.',
    'settings.release.error.timeout' => 'Die Verbindung zur Updatequelle hat zu lange gedauert. Bitte später erneut prüfen.',
    'settings.release.error.tls' => 'Die HTTPS-Zertifikatsprüfung ist fehlgeschlagen. Bitte den Zertifikatsspeicher der PHP-Installation prüfen lassen.',
    'settings.release.error.rate_limit' => 'Die Updatequelle begrenzt gerade die Anfragen dieser Hosting-IP. Bitte später erneut prüfen oder das Release direkt auf Forgejo öffnen.',
    'settings.release.error.http' => 'Die Updatequelle hat die Updateabfrage abgelehnt. Bitte später erneut prüfen oder das Release direkt auf Forgejo öffnen.',
    'settings.release.error.invalid_response' => 'Die Antwort der Updatequelle ist ungültig oder zu groß.',
];
