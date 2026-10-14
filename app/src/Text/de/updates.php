<?php
declare(strict_types=1);
return [
    'update.maintenance.running_title' => 'Wir aktualisieren die Vereinskiste',
    'update.maintenance.running_text' => 'Die Anwendung ist während der Aktualisierung kurzzeitig nicht verfügbar. Bitte versuche es in wenigen Minuten erneut.',
    'update.maintenance.admin_login' => 'Anmeldung für Administratoren',
    'settings.github.error.transport_unavailable' => 'Das Hosting erlaubt keine HTTPS-Abfrage: bitte die PHP-Erweiterung cURL oder allow_url_fopen aktivieren.',
    'settings.github.error.connection' => 'GitHub ist vom Hosting aus nicht erreichbar. Bitte DNS und ausgehende HTTPS-Verbindungen prüfen lassen.',
    'settings.github.error.timeout' => 'Die Verbindung zu GitHub hat zu lange gedauert. Bitte später erneut prüfen.',
    'settings.github.error.tls' => 'Die HTTPS-Zertifikatsprüfung ist fehlgeschlagen. Bitte den Zertifikatsspeicher der PHP-Installation prüfen lassen.',
    'settings.github.error.rate_limit' => 'GitHub begrenzt gerade die Anfragen dieser Hosting-IP. Bitte später erneut prüfen oder das Release direkt auf GitHub öffnen.',
    'settings.github.error.http' => 'GitHub hat die Updateabfrage abgelehnt. Bitte später erneut prüfen oder das Release direkt auf GitHub öffnen.',
    'settings.github.error.invalid_response' => 'Die Antwort der Updatequelle ist ungültig oder zu groß.',
];
