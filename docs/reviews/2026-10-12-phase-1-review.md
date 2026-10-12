# Review von Phase 1

Geprüft wurden der Kassenbuch-Stand einschließlich Task 10 und die Umsetzung
von Tasks 11–14. Die Prüfung umfasst Quellcode, PostgreSQL-/HTTP-Tests,
Release-Build und lokale Browser-Vorschauen. Sie ersetzt keinen Test auf dem
produktiven Hosting und keinen unabhängigen Sicherheits-Audit.

## Umgesetzt

- Task 10 geprüft: Rollen, letzter aktiver Vorstand, Deaktivierung,
  Passwortwechsel und Audit. Die Passwortregeln berücksichtigen jetzt die
  bcrypt-Grenze von 72 Bytes; Passwortänderungen beenden andere Sitzungen.
- Task 11: SMTP mit TLS/SSL, optionale Konfiguration, Reset-Links mit einer
  Stunde Gültigkeit, Hash-Speicherung, atomarer Einmalverbrauch, generische
  Bestätigung, Versandbegrenzung je Benutzer und Invalidierung bei geänderten
  Zugangsdaten. Tests verwenden einen Fake-Transport.
- Task 12: Web-Einrichtung ohne vorab konfigurierte Datenbank, Systemprüfung,
  Installationssperre, erster Vorstand, geschützte Updates mit Backup-Bestätigung
  und Wartungsseite. Installations- und Update-Migrationen laufen gemeinsam in
  einer Transaktion; parallele Migrationsläufe werden serialisiert.
- Task 13: Labels und CSRF in den gerenderten Formularen, eindeutige IDs,
  Viewport, Sprunglink, sichtbarer Tastaturfokus und mindestens 44 Pixel hohe
  Bedienelemente. Buchungsliste, Buchungsformular, Bericht und Installer wurden
  in Edge mit 360 Pixel breiter Geräteemulation geprüft; kein horizontaler
  Seitenüberlauf auf diesen Vorschauseiten.
- Task 14: Release-Build mit gebündelten Produktionsabhängigkeiten, Prüfung des
  ZIP-Inhalts, Apache-Rewriting, CI-Konfiguration, Hosting-/Update-Anleitung und
  Anleitung zur gemeinsamen Sicherung von Datenbank, Konfiguration und Belegen.
  Die bisherige globale `vendor/`-Ignore-Regel ignorierte auch die vorhandenen
  Tabler-Dateien. Sie wurde auf `app/vendor/` beschränkt, damit ein frischer
  Checkout die gebündelten UI-Dateien enthalten kann.

## Verbleibende Befunde im Kassenbuch

1. **Buchungen vor dem Eröffnungsdatum eines Kontos (behoben in diesem Branch):** `BookingService` und
   `TransferService` validieren dieses Datum nicht gegen `opening_date`.
   `AccountRepository::balance()` lässt ältere Buchungen aus, die
   Zeitraumsauswertung berücksichtigt sie. Die Fachregel sollte festgelegt
   werden: frühere Buchungen ablehnen oder ihre Behandlung konsistent erklären
   und darstellen. Ein vorhandener Saldo-Test bestätigt das derzeitige Auslassen.
2. **Audit für Konten und Kategorien (behoben in diesem Branch):** Erstellen, Umbenennen und Deaktivieren
   schreiben derzeit keine Audit-Einträge. Die Benutzerverwaltung und Abschlüsse
   werden bereits protokolliert; die Stammdatenpflege sollte folgen.
3. **Namenseindeutigkeit bei parallelen Anfragen (behoben in diesem Branch):** `accounts` hat keinen
   eindeutigen Namensindex. `categories.name` ist nur case-sensitiv eindeutig,
   während die Anwendung mit `lower(name)` prüft. Zwei gleichzeitige Anfragen
   können die Prüfung bestehen und gleichnamige Konten beziehungsweise nur in
   Groß-/Kleinschreibung unterschiedliche Kategorien erzeugen. Die
   Datenbank-Constraints sollten dieselbe Regel wie die Anwendung durchsetzen.

   Behebung: (1) `BookingService` und `TransferService` lehnen ein Buchungsdatum
   vor dem Eröffnungsdatum des Kontos mit einer Validierungsmeldung ab (Anlegen,
   Ändern von Datum oder Konto, Korrekturbuchung, beide Konten bei Umbuchungen;
   Storno und Wiederherstellung bleiben möglich). (2) Anlegen, Umbenennen,
   Aktivieren und Deaktivieren von Konten und Kategorien schreiben in derselben
   Transaktion einen Protokolleintrag, der auf `/audit-log` mit deutschen
   Bezeichnungen erscheint. (3) Migration `0005_unique_account_category_names.sql`
   erzwingt `lower(name)` eindeutig für beide Tabellen; ein Wettlauf (SQLSTATE
   23505) ergibt denselben Formularfehler wie die Anwendungsprüfung.

## Wartbarkeit und Duplikate

Neue Komponenten bleiben klein; SMTP, Reset-Fachlogik, Einrichtung, Updates und
HTTP-Controller sind getrennt. Gemeinsame Transaktionen verwenden `Transaction`.
Im vorhandenen `UserRepository` bleibt eine eigene, fast identische
Transaktionsimplementierung. Weitere Ähnlichkeiten gibt es bei der Pflege von
Konten und Kategorien. Diese Stellen sind Kandidaten für eine gezielte
Bereinigung; eine allgemeine CRUD-Abstraktion wäre für den aktuellen Umfang
unverhältnismäßig. Es wurde kein Kompatibilitätszweig für alte Sitzungen ergänzt;
Sitzungen ohne Zugangsdaten-Fingerabdruck verlangen eine neue Anmeldung.

## Grenzen der Verifikation

Abschließende lokale Prüfung: **328 Tests, 2.141 Assertions, keine Fehler**.
Das Release-ZIP wurde gebaut und geprüft. Die Installer-Seite startete aus dem
entpackten ZIP mit dessen eigenen Abhängigkeiten (HTTP 200, CSRF-Formular).

- Tests laufen in einer separaten Entwicklungsdatenbank, damit parallele Chats
  sich nicht durch das Zurücksetzen des Schemas beeinflussen.
- Echter SMTP-Versand benötigt die Zugangsdaten des Vereins und wurde nicht
  ausgeführt.
- Die GitHub-CI wurde konfiguriert, aber noch nicht auf GitHub ausgeführt.
- PHP 8.4 ist in der CI-Matrix vorgesehen; lokal wurde PHP 8.1 verwendet.
- Die echte Hosting-Umgebung, reale Mobilgeräte und ein Screenreader wurden
  nicht getestet. Die Browser-Prüfung verwendete synthetische Daten.
