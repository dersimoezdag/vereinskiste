# Vereinskiste

Kassenbuch fuer kleine Vereine (PHP 8.4, Slim 4, PostgreSQL 15). Lizenz: AGPL-3.0, siehe `LICENSE`.

## Entwicklung

Der laufende Ausbau um Vereinsprofil, Finanzkontakte und Importgrundlagen ist im
[Umsetzungsstand](docs/plans/ausbau/09-umsetzungsstand.md) dokumentiert.

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
sh scripts/build-release.sh 0.1.5
php scripts/verify-signed-release.php dist/vereinskiste-0.1.5.zip 0.1.5
```

Der Build installiert Produktionsabhängigkeiten in einem temporären Verzeichnis.
Lokale Konfiguration, Belege, Tests und Entwicklungscontainer gelangen nicht ins
Paket. Die CI prüft Tests gegen PostgreSQL und erzeugt ein geprüftes Release-ZIP.

Die ausführliche Hosting-, SMTP- und Wiederherstellungsprüfung steht in [docs/hosting.md](docs/hosting.md).

Ab 0.1.5 werden stabile Releases signiert. Einrichtung, Schlüsselverwaltung und
automatische Installation beschreibt [docs/automatic-updates.md](docs/automatic-updates.md).

### System-E-Mails und Eingabeformate

Unter **Einstellungen → E-Mail-Konten** können Administratoren und Vorstand das
Systemkonto für Passwort-Reset-E-Mails aktivieren und SMTP-Host, Port,
TLS-Verfahren, Absenderadresse und Zugangsdaten ändern. Die No-Reply-Adresse ist
frei wählbar. Ein leeres Passwortfeld behält das bisherige Passwort bei;
„Gespeichertes Passwort entfernen“ löscht es ausdrücklich. Bestehende SMTP-Daten
aus der Installation werden verwendet, bis eigene Kontoeinstellungen gespeichert
werden. Webanwendung und Cron-Worker verwenden dieselbe, nach Versandzweck
organisierte Konfiguration. Weitere Konten (z. B. Newsletter) können später unter
einem eigenen Versandzweck ergänzt werden. Die Zugangsdaten bleiben in
`app/config/mail-accounts.json` außerhalb des Webroots (Dateirechte `0600`);
dieses Verzeichnis muss für den PHP-Prozess schreibbar sein und gesichert werden.

Die Mitgliederliste bietet einen CSV-Export sämtlicher Treffer der aktuellen
Suche und Statusauswahl. Er verwendet UTF-8 mit BOM, Semikolon und deutsche
Geburtsdaten. Eingabemasken zeigen Datumsfelder als `TT.MM.JJJJ`, Beträge als
`1.234,56` und IBANs in Vierergruppen. Die bisherigen serverseitigen
Speicherformate bleiben erhalten. Telefonnummern und internationale
Postleitzahlen werden nicht auf ein deutsches Zahlenformat beschränkt.

### Manueller Belegeingang, Browser-OCR und Erstattungen

Belege werden ausschließlich gezielt unter **Rechnungen → Import** hochgeladen. Es gibt keinen automatischen E-Mail-Abruf und keine Übernahme von E-Mail-Anhängen. Unter **Belegeingang** erscheinen die hochgeladenen Entwürfe zur Prüfung.

Die Anwendung läuft auf PHP-Webhosting, auch ohne Node, ClamAV, Tesseract-Binaries, Poppler, Bubblewrap oder eigene Hintergrunddienste auf dem Server. Für Bilder und PDFs kann ein berechtigter Benutzer auf der Rechnungsseite **Text erkennen (OCR im Browser)** starten. Tesseract.js verarbeitet deutsche und englische Texte auf dem Gerät des Benutzers; PDF.js liest vorhandenen PDF-Text oder rendert bis zu fünf Seiten zur OCR. Alle Programme, WASM-Dateien und Sprachdaten werden vom eigenen Hosting ausgeliefert. Es werden keine Belege an einen externen OCR-Dienst oder ein CDN geschickt. Erst nach bewusstem Start werden erkannte Texte als unverbindliche Vorschläge zur eigenen Installation zurückgesendet. Lange Dokumente oder fehlgeschlagene OCR können manuell erfasst werden. XML-Rechnungen werden weiterhin im PHP-Cronjob strukturiert ausgewertet.

Node wird nur für Tests und Paketbau benötigt: `npm ci --prefix tests --ignore-scripts` installiert festgeschriebene Abhängigkeiten; `scripts/vendor-browser-ocr.cjs` legt die benötigten Browser-Dateien für das Release bereit. Der Server benötigt diese Build-Werkzeuge nicht. Die OCR-Seiten erlauben in der Content-Security-Policy WebAssembly, aber kein JavaScript-`eval`; Worker und Ressourcen bleiben auf die eigene Herkunft beschränkt.

Dateiprüfungen: zugelassene MIME-Typen PDF/JPEG/PNG/XML, maximal 10 MiB je Beleg, Bildgrößenlimit, XML ohne externe Entitäten und private Speicherung außerhalb des Webroots. PDFs mit erkennbaren Skripten, Startaktionen, Formularen, eingebetteten Dateien, externen Verweisen, Verschlüsselung oder Objektstreams werden abgewiesen. Dateinamen werden bereinigt; Downloads benötigen Fachberechtigungen und werden als Anhänge mit restriktiver CSP ausgeliefert. Prüfsummen verhindern doppelte Belege.

**Diese Prüfungen sind kein Virenscan.** Die Oberfläche kennzeichnet Belege als formatgeprüft, nicht als virenfrei. PDF-Filter sind zusätzliche strukturelle Schutzmaßnahmen, keine vollständige Schadsoftwareerkennung. Nur Belege aus geprüften Quellen hochladen und auch lokal aktuelle Schutzsoftware und PDF-/Bildbetrachter verwenden. OCR bestätigt weder Echtheit noch Zahlungsberechtigung und übernimmt keine Bankverbindung. Eine echte serverseitige Virenprüfung würde einen separat eingerichteten, geeigneten Prüfdienst benötigen; es wird kein solcher Dienst ohne Konfiguration aufgerufen.

Bei einer Rechnung **Zahlung / Auslagenerstattung** ausfüllen: Lieferantenzahlung oder Erstattung an ein ausgewähltes Mitglied, unabhängig geprüfte IBAN, Verwendungszweck und Anlass/Veranstaltung. Der Beleg muss geprüft sein, bevor eine Person mit Freigaberecht die Zahlung freigibt. Standardmäßig kann der Kassenwart dies selbst erledigen. Unter **Einstellungen → Zahlungsfreigabe** kann der Verein ein verpflichtendes Vier-Augen-Prinzip aktivieren. Änderungen der Regel und die bei Freigabe angewandte Regel werden protokolliert. Erstattungen an das eigene verknüpfte Mitglied erfordern immer eine andere Person.

Die Freigabe führt keine Überweisung aus. Nach Auszahlung eine tatsächliche Ausgabebuchung zuordnen; mehrere Teilzahlungen sind möglich. Eine Bankbuchung kann zunächst genau einer Rechnung zugeordnet werden; Sammelzahlungen werden noch nicht aufgeteilt. Stornierte Buchungen zählen nicht als Zahlung. Änderungen an zugeordneten Buchungen erfordern zuerst das begründete Aufheben der Zuordnung. Alle Zahlungsentscheidungen und Zuordnungsänderungen werden unveränderlich protokolliert. Rechnungslisten zeigen unbezahlte, teilweise bezahlte und bezahlte Rechnungen.

### Vorstand und Administrator

Bei neuer Zuweisung der Benutzerrolle **Vorstand** wird die Rolle **Admin** ausdrücklich mit gespeichert. Danach lassen sich beide Rollen unabhängig verwalten: Admin kann entzogen werden, obwohl Vorstand bestehen bleibt. Beim Entfernen von Vorstand fragt das Formular, ob Admin ebenfalls entfernt werden soll. Zusätzliche Fachrollen gelten weiterhin. Mindestens ein aktiver Administrator muss bestehen bleiben. Vereinsämter beim Mitglied ändern diese Benutzerrollen nicht.

Die Migration `0016_vorstand_admin_assignment.sql` ergänzt bei vorhandenen Vorstandskonten einmalig Admin, damit bisherige Vollrechte erhalten bleiben. Ein späterer manueller Entzug wird nicht automatisch rückgängig gemacht.
