# Hosting und Betriebsprüfung

Die Anwendung benötigt PHP 8.4 oder neuer, PostgreSQL 15 oder neuer und die
Erweiterungen `pdo_pgsql`, `mbstring`, `fileinfo`, `gd`, `openssl`.
PHP 8.1 erhält keine Sicherheitsupdates mehr. Unterstützte Versionen:
https://www.php.net/supported-versions.php

## Einrichtung ohne SSH

1. Eigene Domain mit gültigem HTTPS-Zertifikat und eigene PostgreSQL-Datenbank
   im Hosting-Panel anlegen. Domain beim Upload vorübergehend sperren.
2. Release entpacken, `app/` und `public/` als benachbarte Verzeichnisse hochladen.
   Als Dokumentenverzeichnis ausschließlich `public/` auswählen; `.htaccess`
   mit hochladen und Apache-Rewriting aktivieren.
3. PHP 8.4/8.5 wählen; Fehleranzeige abschalten, geschützte Fehlerprotokolle
   einschalten. Nur PHP/Hosting-Benutzer darf `app/config/` und `app/storage/`
   schreiben. Auf einem Unix-Hosting: private Verzeichnisse 0700, Konfiguration
   0600, öffentliche Dateien 0644. Falls PHP über eine andere Benutzergruppe
   läuft, passende Gruppenrechte statt 0777 verwenden.
4. Im Passwortmanager einen zufälligen Einrichtungsschlüssel mit mindestens
   32 Zeichen aus Buchstaben, Ziffern, `_` und `-` erzeugen. Als reinen Text
   unter `app/config/setup.key` hochladen, außerhalb des Webroots und mit 0600.
   Jeder Betreiber erzeugt seinen eigenen Schlüssel; das ZIP enthält keinen.
5. Domain freigeben, `/install` über HTTPS öffnen, Schlüssel eingeben und dann
   Systemprüfung, Datenbank, Verein und ersten Administrator einrichten. Der
   Schlüssel wird nach Erfolg entfernt; Konfiguration und Installationssperre
   verhindern eine erneute Einrichtung. Schlüssel niemals in eine URL setzen.
6. SMTP nach Angaben des Anbieters einstellen: STARTTLS/587 oder TLS/465,
   erlaubter Absender und eigene Zugangsdaten. Zertifikatsprüfung bleibt aktiv.
7. Bei aktiviertem SMTP einen exklusiven Cronjob jede Minute für die private
   Datei `app/cron.php` einrichten. Bei Hetzner konsoleH im Cronmanager als
   Befehl `/usr/bin/php /ABSOLUTER/PFAD/app/cron.php` verwenden. Der PHP-Pfad
   folgt der im Panel gewählten Version. Kein öffentliches Cron-Skript anlegen.
   Anleitung: https://docs.hetzner.com/de/managed/administration-on-konsoleh/cronmanager/

Reset-Anfragen werden zuerst unabhängig von vorhandenen Benutzerkonten in
eine kurzlebige Warteschlange aufgenommen. Erst der Cronjob prüft das Konto
und versendet die Nachricht. Die Zustellung kann etwa eine Minute dauern;
Anfragen verfallen nach 15 Minuten, Reset-Links nach einer Stunde. Bei
Versandfehlern wird der Link verworfen; nach einer Minute erneut anfordern.
Ohne SMTP kann ein anderer Vorstand ein neues Passwort setzen.

## HTTPS, Proxy und private Dateien prüfen

HTTP-Seitenaufrufe werden zu HTTPS umgeleitet; unsichere POST-Anfragen werden
abgelehnt. HTTPS-Antworten erhalten HSTS. Cookies verwenden HttpOnly und
SameSite=Strict sowie Secure bei HTTPS. Sitzungsdateien und gemeinsame
Versuchslimits liegen unter `app/storage/`, außerhalb des Webroots.
`X-Forwarded-Proto` und `X-Forwarded-For` werden nicht als Vertrauensnachweis
verwendet. Bei vorgeschaltetem Proxy muss der Betreiber dafür sorgen, dass
der Webserver HTTPS zuverlässig aus der geprüften Proxy-Verbindung erkennt
und das serverseitige `HTTPS`-Attribut setzt; frei übermittelte Client-Header
dürfen dieses Attribut nicht setzen. Das Login-IP-Budget nutzt `REMOTE_ADDR`;
bei Proxybetrieb gilt es gemeinsam für dessen Nutzer. HTTPS-Prüfung in der
Produktionskonfiguration nicht abschalten.

Vor Freigabe in einem privaten Browser prüfen:

- `http://DOMAIN/login` wechselt zu HTTPS; Cookies sind Secure/HttpOnly/Strict.
- `/app/config/config.php`, `/config/config.php`, `/app/storage/`, `/vendor/`
  und `/cron.php` liefern weder Dateien noch Verzeichnislisten.
- Ohne Einrichtungsschlüssel ist nur die Freigabemaske erreichbar.
- Sechster Loginversuch derselben Adresse innerhalb einer Minute erhält 429,
  auch nach Cookie-Löschen. Nach einer Minute funktioniert Anmeldung wieder.
- Für aktives, unbekanntes und inaktives Konto ist die Reset-Antwort gleich.
  Nur das aktive Konto erhält per Cronjob Mail; Link ist nach Benutzung ungültig.
- Gültiger SMTP-Transport funktioniert, falsche Zugangsdaten erzeugen keine
  Details im Browser, unbekannte TLS-Zertifikate werden abgelehnt.
- Rollen, Buchung/Änderung/Storno, Bericht und Belegdownload funktionieren.

Reset-URLs enthalten temporäre Zugangstoken. Zugriff auf Webserver-Logs
beschränken, keine externen Analyse-Skripte auf diesen Seiten einbinden.
Gemeinsame Budgets je Minute: Login 5/Konto, 60/IP, 120/Installation;
Reset 1/Adresse, 5/IP, 10/Installation. Der Zähler enthält keine Klartextadressen.
Bei beschädigtem Zählerspeicher werden Anfragen abgelehnt. Nicht regelmäßig
löschen: das würde den Schutz zurücksetzen.

## Backup, Wiederherstellung und Update

Unter **Einstellungen → Version & Updates → Auf Updates prüfen** wird das
neueste öffentliche stabile GitHub-Release aus
https://github.com/dersimoezdag/vereinskiste/releases geprüft. Dort muss ein
vollständiges Installationspaket `vereinskiste-VERSION.zip` als Release-Asset
veröffentlicht sein (z. B. Tag `v0.2.0`, Paket `vereinskiste-0.2.0.zip`).
GitHubs automatisch erzeugtes Quellcode-ZIP enthält keine gebündelten
PHP-Abhängigkeiten und eignet sich nicht für die Hosting-Installation.
Die Prüfung benötigt ausgehendes HTTPS zu `api.github.com`, keinen GitHub-Token
für öffentliche Releases. Netzwerkfehler werden als fehlgeschlagene Prüfung
angezeigt. Das Paket herunterladen, Backup erstellen und nach dem unten
beschriebenen Verfahren hochladen; anschließend hier das Datenbank-Update
ausführen. Entwürfe und Vorabversionen werden nicht als Update angeboten.

Domain sperren und Mail-Cron pausieren. PostgreSQL-Dump, `app/config/`,
Belegdateien aus `app/storage/receipts/` und genaue Release-Version gemeinsam
sichern. Backups verschlüsseln und getrennt vom Hosting aufbewahren.
Sitzungsdateien sind keine dauerhaft benötigten Geschäftsdaten.

Wiederherstellung zuerst in einer getrennten Datenbank und einer abgesicherten
Testdomain durchführen. Passendes Release, Datenbank und private Dateien aus
demselben Backup wiederherstellen; DB-Zugang und `base_url` anpassen.
Testinstallation zunächst ohne SMTP betreiben, damit alte Anfragen keine
echten Nachrichten auslösen. Über das Datenbankwerkzeug im Panel ausführen:

```sql
DELETE FROM password_reset_requests;
DELETE FROM password_resets;
```

Alte Dateien in `app/storage/sessions/` entfernen, damit alte Sitzungen nicht
wieder gültig werden. Den bestehenden Rate-Limit-Speicher erhalten, sofern
die ursprüngliche Installation zurückgespielt wird. In einer neuen privaten
Testinstallation wird eigener Speicher angelegt. Erste Anmeldung, Rollen,
Kontosalden, Anzahl und Verlauf der Buchungen sowie SHA-256 und Download
mehrerer Belege mit dem Sicherungsstand vergleichen. Danach erst Cron/Domain
freigeben. Auf demselben produktiven Datenbestand niemals Test-Suites starten.

Bei Updates Cron pausieren, konsistentes Backup erstellen und Domain während
des Dateiwechsels sperren. `config/` und `storage/` erhalten, `vendor/` vollständig
ersetzen. Als Vorstand `/update` ausführen; Migration 0006 ergänzt die
Reset-Warteschlange. Cron erst danach aktivieren. Ein Rollback braucht das
passende alte Release und dessen Datenbanksicherung gemeinsam.

Diese Anleitung ersetzt keinen erfolgreichen Hosting-Test: vor dem ersten
Produktivbetrieb alle obigen Punkte mit den tatsächlichen Zugängen prüfen.
