<?php
declare(strict_types=1);

return [
    'mail.settings.title' => 'E-Mail-Konten',
    'mail.system.title' => 'System-E-Mails (No-Reply)',
    'mail.system.description' => 'Dieses Konto versendet Systemnachrichten wie Passwort-Reset-E-Mails. Tragen Sie Ihre gewünschte No-Reply-Absenderadresse ein.',
    'mail.enabled' => 'System-E-Mail-Versand aktivieren',
    'mail.field.host' => 'SMTP-Host',
    'mail.field.port' => 'SMTP-Port',
    'mail.field.from' => 'Absenderadresse (No-Reply)',
    'mail.field.from_name' => 'Absendername',
    'mail.field.username' => 'SMTP-Benutzername',
    'mail.field.encryption' => 'Verschlüsselung',
    'mail.field.password' => 'SMTP-Passwort',
    'mail.password.keep' => 'Ein Passwort ist gespeichert. Lassen Sie das Feld leer, um es beizubehalten.',
    'mail.password.empty' => 'Noch kein Passwort gespeichert. Bei SMTP mit Anmeldung hier das Passwort eintragen.',
    'mail.password.clear' => 'Gespeichertes Passwort entfernen',
    'mail.test.button' => 'Test-E-Mail senden',
    'mail.test.recipient' => 'Test-Empfänger',
    'mail.test.hint' => 'Der Test verwendet die oben eingetragenen Werte, ohne zu speichern. Leer lassen, um an die Absenderadresse zu senden.',
    'mail.test.sent' => 'Test-E-Mail wurde an {to} gesendet.',
    'mail.test.failed' => 'Test-E-Mail konnte nicht gesendet werden.',
    'mail.test.invalid_recipient' => 'Bitte eine gültige Empfängeradresse angeben.',
    'mail.test.not_configured' => 'SMTP-Host und Absenderadresse müssen für den Test ausgefüllt sein.',
];
