# Signierte automatische Updates

Ab Version 0.1.5 kann der Vorstand unter Einstellungen → Version & Updates
signierte GitHub-Releases installieren. Eine ältere Installation muss einmal
manuell auf 0.1.5 aktualisiert werden: vollständiges Release-ZIP hochladen,
`app/config` und `app/storage` erhalten und erforderliche Migrationen ausführen.

## Voraussetzungen und Ablauf

PHP 8.4 mit ZIP und Sodium, mindestens 128 MiB PHP-Speicher sowie cURL oder
`allow_url_fopen`. Der DocumentRoot muss ausschließlich auf `public/` zeigen.
`app/storage` muss beschreibbar sein. Ausgehendes HTTPS zu GitHub und dessen
Downloadservern sowie Platz für Paket, entpackte Version und Datenbanksicherung
sind erforderlich. Vor jedem Update zusätzlich Datenbank, Konfiguration und
private Dateien außerhalb des Hostings sichern.

Der Updater prüft die Ed25519-Signatur des Manifests und anschließend Größe und
SHA-256 des Pakets. Unsignierte Releases und Versionsrückschritte werden abgelehnt.
Er entpackt in eine private Versionsablage, aktiviert den Wartungsmodus, wartet
auf laufende Schreibzugriffe und sichert die Datenbank. Migrationen laufen in
einer Transaktion. Erst nach einem tatsächlichen Starttest der neuen Anwendung
wird der Wartungsmodus aufgehoben. Konfiguration und Belege werden nicht ersetzt.

Die Fortschrittsseite führt kurze Arbeitsschritte aus. Der reguläre Cron kann
einen unterbrochenen Vorgang weiterführen; normale Jobs pausieren während der
Wartung. Die Fortschrittsseite für den abschließenden Starttest geöffnet lassen.
Bei fehlgeschlagenem Starttest wird die vorherige Version samt Datenbankstand
wiederhergestellt. Ein Update nach erfolgreichem Abschluss rückwärts einzuspielen
ist kein unterstützter Vorgang: dabei könnten zwischenzeitliche Buchungen verloren gehen.

Automatische Updates unterstützen die reguläre Vereinskiste-Datenbank im
`public`-Schema. Eigene Views, Fremdschema-Abhängigkeiten und andere nicht
unterstützte Schemaobjekte erfordern ein manuelles Update. Grenzen: 50.000
Datenbankzeilen, 64 MiB Sicherungsdaten, 256 MiB entpackte Paketdateien.
Erreichen einer Grenze bricht den Vorgang ab; keine Schutzprüfung deaktivieren.

## Fehler und Wiederherstellung

Bei `recovery_required` bleibt die Anwendung gesperrt. Unter
`/settings/auto-update` kann ein Vorstand die Wiederherstellung erneut versuchen.
Die privaten Sicherungen und Versionsablagen unter `app/storage/updates` behalten.
Sie enthalten personenbezogene Daten und müssen durch Backups und restriktive
Dateirechte geschützt werden. Keine Wartungsdateien löschen, solange die
Datenbankwiederherstellung nicht erfolgreich abgeschlossen ist.

Wenn die Wiederherstellung dauerhaft scheitert, Domain und Cron im Hostingpanel
sperren und das zuvor extern gesicherte vollständige Paket, die Datenbank und
private Dateien gemeinsam wiederherstellen. Dies zuerst auf einer getrennten
Testinstallation prüfen; das Verfahren steht in `hosting.md`.

## Signierschlüssel und Releases

Der private Schlüssel liegt lokal in `.release-secrets/signing.key` und als
GitHub-Actions-Secret `VK_RELEASE_SIGNING_KEY`. Die lokale Datei ist Git-ignoriert
und gehört weder ins Release noch aufs Hosting. Eine verschlüsselte Offlinekopie
aufbewahren. Der öffentliche Prüfschlüssel in `app/update-trust.json` wird an
Installationen ausgeliefert. Eine Signatur bestätigt Herkunft und Unverändertheit;
die Qualität einer neuen Version muss weiterhin durch Reviews und Tests geprüft werden.

Der Workflow **Signed release** kann auf `main` mit einer Versionsnummer
ausgeführt werden. Er baut und testet zuerst, gibt den privaten Schlüssel nur
dem Signierschritt und veröffentlicht ZIP, SHA-256, Manifest und Signatur.
Bestehende Releases werden dabei nicht überschrieben.

Lokaler Build mit PHP, Composer und POSIX-Shell:

```sh
sh scripts/build-release.sh 0.1.5
php scripts/verify-signed-release.php dist/vereinskiste-0.1.5.zip 0.1.5
```

Bei Verlust oder Kompromittierung des Schlüssels keine weiteren Releases damit
signieren. Neue Schlüssel müssen über einen vertrauenswürdigen manuellen Upload
des öffentlichen Prüfschlüssels an bestehende Installationen verteilt werden;
ein heruntergeladenes Paket kann seinen eigenen Prüfschlüssel nicht austauschen.
