# Vereinskiste

Kassenbuch fuer kleine Vereine (PHP 8.4, Slim 4, PostgreSQL 15). Lizenz: AGPL-3.0, siehe `LICENSE`.

## Entwicklung

Auf dem Entwicklungsrechner werden nur Docker und Docker Compose benoetigt
(kein PHP, Composer oder PostgreSQL). `docker-compose.dev.yml` und `Dockerfile.dev`
dienen nur der Entwicklung und sind nie Teil des Release.

Tests ausfuehren:

    docker compose -f docker-compose.dev.yml run --rm php sh -c "cd app && composer install && vendor/bin/phpunit"

Nur ein Teil, z. B. die Migrationstests:

    docker compose -f docker-compose.dev.yml run --rm php sh -c "cd app && vendor/bin/phpunit tests/Db"

Aufraeumen: `docker compose -f docker-compose.dev.yml down -v`

Parallele Chats oder Testläufe brauchen jeweils eine eigene Testdatenbank, da
die Tests das Schema der Testdatenbank zurücksetzen. Eine zusätzliche Datenbank
im Entwicklungscontainer anlegen und `VK_TEST_DSN` für den Testlauf überschreiben.
Niemals die Tests gegen eine produktive Datenbank ausführen.

## Installation auf Webhosting (z. B. Hetzner)

Voraussetzungen: PHP ab 8.4 mit `pdo_pgsql`, `mbstring`, `fileinfo`, `gd` und
`openssl`, PostgreSQL ab 15, HTTPS, mindestens 64 MB PHP-Speicher.
Die Anwendung wird direkt unter einer Domain betrieben, etwa
`https://verein.example.org`, nicht in einem URL-Unterverzeichnis.

1. Das geprüfte Release-ZIP herunterladen und entpacken. Das Paket enthält
   bereits alle PHP-Abhängigkeiten; Composer ist auf dem Webhosting nicht nötig.
2. Im Hosting-Panel eine eigene PostgreSQL-Datenbank anlegen und die Zugangsdaten
   bereithalten. Die Anwendung benötigt ein leeres `public`-Schema.
3. `public/` als Webroot der Domain einrichten (bei Hetzner entsprechend das
   Dokumentenverzeichnis einstellen). `app/` liegt als benachbartes Verzeichnis
   **außerhalb** des Webroots. `public/index.php` erwartet `../app/bootstrap.php`.
   Den Inhalt beider Verzeichnisse per FTP/SFTP hochladen, einschließlich `.htaccess`.
4. PHP aktivieren, HTTPS einrichten und `app/config/` sowie `app/storage/` für
   den PHP-Prozess beschreibbar machen. Keine pauschalen Schreibrechte für alle
   Benutzer vergeben. Bei Apache muss URL-Rewriting aktiv sein.
5. Einen zufälligen Einrichtungsschlüssel (mindestens 32 Zeichen aus Buchstaben, Ziffern, `_` und `-`) in `app/config/setup.key` außerhalb des Webroots hochladen. `/install` über HTTPS öffnen und mit diesem Schlüssel freigeben. Systemprüfung prüfen, Datenbank-Zugangsdaten,
   Vereinsname, HTTPS-Adresse und den ersten Administrator eintragen.
6. SMTP ist optional: Host, Port, Absender und Zugangsdaten eintragen. `TLS` mit
   Port 587 oder `SSL` mit Port 465 gemäß dem Mailanbieter wählen. Ohne SMTP
   kann ein anderer Vorstand ein vergessenes Passwort zurücksetzen.
7. Nach erfolgreicher Einrichtung anmelden. Weitere Benutzer, Konten und
   Kategorien anlegen. Bei aktiviertem SMTP einen exklusiven Cronjob jede Minute für `app/cron.php` einrichten; Passwort-Reset-Mails werden im Hintergrund versendet.
   Der Anfangssaldo eines Kontos gilt zum Eröffnungsdatum; Buchungen und
   Umbuchungen davor werden abgelehnt. Das Eröffnungsdatum daher auf den Tag
   des ersten Eintrags (oder früher) setzen.

Nur `public/` darf öffentlich erreichbar sein. Die Konfiguration enthält
Datenbank- und gegebenenfalls SMTP-Zugangsdaten; Belege liegen unter
`app/storage/receipts/` und werden nur über geschützte Anwendungsrouten geladen.
Der Betreiber sollte HTTPS erzwingen und Zugriff auf Webserver-Logs beschränken,
da Passwort-Reset-Links temporäre Zugangstoken enthalten.

## Updates

1. Ein gemeinsames Backup von PostgreSQL-Datenbank, `app/config/` und
   `app/storage/` erstellen; auch das bisherige Release aufbewahren.
2. Für den Datei-Upload die Domain im Hosting-Panel vorübergehend sperren oder
   eine Wartungsseite einschalten, damit keine Mischung zweier Codeversionen läuft.
3. Den Anwendungscode aus dem neuen Paket hochladen. `app/config/` und
   `app/storage/` dabei **nicht überschreiben oder löschen**. `vendor/` durch die
   neue gebündelte Version ersetzen.
4. Die Domain wieder freigeben und als Vorstand `/update` öffnen. Bei ausstehenden
   Migrationen zeigt die Anwendung eine Wartungsseite; Anmeldung und Update
   bleiben erreichbar. Das Backup bestätigen und Update ausführen.
5. Anmeldung, Buchungsliste, Übersicht und Belegdownload prüfen.

Bei einem Migrationsfehler wird die gesamte Update-Transaktion zurückgerollt.
Die Anwendung bleibt im Wartungsmodus. Nach einem erfolgreichen Update nicht
einfach alten Code über die neue Datenbank kopieren: bei einem Rollback das
bisherige Release **und** das zugehörige Backup gemeinsam wiederherstellen.

Migration 0005 macht Konto- und Kategorienamen ohne Beachtung der Groß-/Klein-
schreibung eindeutig. Gibt es bereits Konten oder Kategorien, die sich nur in
der Schreibweise unterscheiden, bricht das Update mit einer Meldung ab und
ändert nichts: die genannten Einträge zuerst in der Anwendung (oder mit
Datenbankwerkzeug) umbenennen und das Update wiederholen.

## Backup und Wiederherstellung

Regelmäßig einen PostgreSQL-Dump über das Hosting-Panel oder ein geeignetes
Datenbankwerkzeug erstellen und Konfiguration sowie Belegdateien sichern.
Für ein konsistentes Backup die Anwendung während der Sicherung sperren.
Backups verschlüsselt außerhalb des Webroots aufbewahren; Zugangsdaten und
personenbezogene Daten sind enthalten.

Eine Wiederherstellung zuerst in einer getrennten Installation testen:
passendes Release hochladen, den Datenbank-Dump einspielen, `config/` und
`storage/` aus demselben Backup zurückspielen und gegebenenfalls die Datenbank-
Zugangsdaten sowie `base_url` in `app/config/config.php` anpassen. Anmeldung,
Kontosalden, Buchungsverläufe und mehrere Belegdownloads prüfen.

## Release bauen

Nur für Entwickler: PHP mit ZIP-Erweiterung, Composer und eine POSIX-Shell.

```sh
sh scripts/build-release.sh 0.1.0
sh scripts/verify-release.sh dist/vereinskiste-0.1.0.zip
```

Der Build installiert Produktionsabhängigkeiten in einem temporären Verzeichnis.
Lokale Konfiguration, Belege, Tests und Entwicklungscontainer gelangen nicht ins
Paket. Die CI prüft Tests gegen PostgreSQL und erzeugt ein geprüftes Release-ZIP.

Die ausführliche Hosting-, SMTP- und Wiederherstellungsprüfung steht in [docs/hosting.md](docs/hosting.md).
