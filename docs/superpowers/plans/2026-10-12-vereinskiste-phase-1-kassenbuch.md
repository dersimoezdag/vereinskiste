# Vereinskiste Phase 1 (Kassenbuch) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein per Web-Installer installierbares Kassenbuch für einen Verein: Konten, Kategorien, Buchungen mit Belegen, lückenloser Änderungsverlauf, Storno/Korrektur/Umbuchung, Jahresabschluss, Übersicht mit CSV/PDF-Export, Rollen; responsiv und mobilfreundlich.

**Architecture:** PHP-8-Anwendung (Slim 4, Twig, PDO/PostgreSQL, reine SQL-Migrationen). Seiten werden serverseitig gerendert (Post-Redirect-Get, Formular-CSRF); CSS-Kit Tabler ist gebündelt, es gibt keinen Build-Schritt und kein Node. Fachlogik liegt in Services ohne HTTP-Bezug; Controller rendern nur. Release-Paket = `app/` (oberhalb des Webroots) + `public/` (Webroot), per FTP hochladbar.

**Tech Stack:** PHP ≥ 8.1, Slim 4, slim/twig-view, PHPUnit 10, PostgreSQL 15, PHPMailer, FPDF (setasign/fpdf), Tabler (gebündelt, MIT).

**Spec:** `docs/superpowers/specs/2026-10-12-vereinskiste-design.md` (Phase 1 = Kassenbuch; Mitglieder sind Phase 2 und bekommen einen eigenen Plan).

## Global Constraints

- Server: nur PHP und PostgreSQL; kein Node, Docker, SSH oder Shell vorausgesetzt (Hetzner Webhosting S).
- Limits: 1 Datenbank, 1 Cronjob, 2 PHP-Prozesse, 192 MB Memory-Limit, 10 GB Speicher.
- Migrationen sind transaktional; ein fehlgeschlagener Schritt hinterlässt die DB unverändert.
- Eine Installation = ein Verein, kein Mandantenmodell.
- Beträge exakt (`NUMERIC(12,2)`, in PHP als String/Integer-Cent, nie `float`); Währung fest EUR.
- Buchungen werden nie gelöscht; Änderungen erzeugen Verlaufseinträge mit Benutzer, Zeitpunkt, Feld alt→neu, Pflichtgrund; Einträge sind unveränderlich.
- Kassenbuch-Liste zeigt keinen Verlauf, nur Kennzeichen „geändert" mit Link; der Verlauf steht in der Detailansicht.
- Korrekturbuchung = eigene sichtbare Buchung (Typ `correction`), vor und nach Abschluss möglich.
- Nach „abgeschlossen bis"-Datum sind Buchungen unveränderbar; Wiederöffnen nur Vorstand mit Pflichtgrund + Protokoll.
- Rollen: `vorstand` (alles), `kassenwart` (Buchungen lesen/schreiben, Mitglieder lesen), `schriftfuehrer` (Mitglieder verwalten, Buchungen lesen), `kassenpruefer` (nur lesen inkl. Verlauf/Export); mehrere Rollen je Benutzer.
- Belege: PDF/JPEG/PNG, max. 10 MB, SHA-256 je Datei, außerhalb des Webroots, Zugriff nur über die Anwendung mit Rechteprüfung; „ersetzt" bleibt der alten Buchungsversion zugeordnet.
- Mail ausschließlich per SMTP, nie `mail()`; ohne SMTP bleibt die App nutzbar, Mail-Funktionen sind abgeschaltet und das wird angezeigt.
- Sicherheit: CSRF-Schutz in jedem Formular, `password_hash`, Session-Härtung, Upload-Prüfung, Twig-Autoescape; keine Personendaten in URLs oder Logs.
- Oberfläche deutsch, Texte zentral abgelegt; Lizenz AGPL-3.0.
- **Responsiv und mobilfreundlich:** nutzbar ab 360 px Breite, Tabellen werden auf kleinen Bildschirmen als Karten dargestellt, Touch-Ziele ≥ 44 px, Beleg-Upload kann direkt die Handykamera nutzen.
- Release enthält `vendor/` und gebündelte Assets; kein Build auf dem Server.

## Review Focus

1. Betrag `0`, negativ, mehr als 2 Nachkommastellen oder > 9.999.999,99: Formular wird mit Feldfehler und Status 422 erneut angezeigt, nichts gespeichert (Task 5).
2. Zwei Benutzer ändern dieselbe Buchung gleichzeitig: zweiter Speichervorgang bekommt 409 mit Hinweis, überschreibt nicht still (Task 6).
3. Hochgeladene Datei ist umbenannte `.exe`, > 10 MB, HEIC-Handyfoto oder > 40 Megapixel: abgelehnt mit klarer Meldung, kein Speicher-Überlauf bei 192 MB (Task 7).
4. Buchung mit Datum ≤ abgeschlossen-bis anlegen, oder bestehende Buchung in den abgeschlossenen Zeitraum verschieben: abgelehnt (Task 9).
5. Deaktivierter Benutzer mit bestehender Session, Benutzer ohne Rolle, letzter Vorstand wird deaktiviert/herabgestuft: Zugriff verweigert bzw. Aktion abgelehnt (Tasks 3, 10).

---

## Dateistruktur

```
app/                         # oberhalb des Webroots
  composer.json  phpunit.xml  bootstrap.php
  src/
    Db/        Connection.php  Migrator.php
    Http/      Kernel.php  Flash.php  Middleware/{Session,Csrf,Auth,Maintenance}.php
    Auth/      Permissions.php  UserRepository.php  AuthService.php
    Ledger/    Money.php  BookingService.php  BookingRepository.php
               ChangeLog.php  ClosingService.php  TransferService.php
               AccountRepository.php
    Receipts/  ReceiptStore.php
    Reports/   SummaryService.php  CsvExporter.php  PdfExporter.php
    Install/   SystemCheck.php  Installer.php  Updater.php
    Mail/      Mailer.php
    Web/       *Controller.php (ein Controller je Bereich)
    Text/      de.php (alle UI-Texte)
  templates/   layout.twig  bookings/  reports/  admin/  install/  auth/
  migrations/  0001_initial.sql  ...
  tests/
  config/      (config.php wird vom Installer erzeugt)
  storage/receipts/
public/        index.php  .htaccess  assets/ (Tabler, eigenes app.css/app.js)
scripts/       build-release.sh  verify-release.sh
LICENSE  README.md
```

Backend-Tests laufen gegen eine echte PostgreSQL (`VK_TEST_DSN`, `VK_TEST_USER`, `VK_TEST_PASS`; Standard: `pgsql:host=localhost;dbname=vereinskiste_test`). HTTP-Tests rufen die Slim-App direkt mit PSR-7-Requests auf und prüfen Status, Redirect und HTML-Auszüge.

---

### Task 1: Gerüst und Migrator

**Files:**
- Create: `app/composer.json`, `app/phpunit.xml`, `app/bootstrap.php`, `public/index.php`, `app/src/Db/Connection.php`, `app/src/Db/Migrator.php`, `app/src/Http/Kernel.php`, `app/tests/TestCase.php`, `LICENSE`, `README.md`, `docker-compose.dev.yml`, `.gitignore`
- Test: `app/tests/Db/MigratorTest.php`, `app/tests/Http/HealthTest.php`

**Dev-Umgebung (nur Entwicklung, nie Teil des Release):** Auf dem Entwicklungsrechner gibt es kein PHP/Composer/PostgreSQL, nur Docker. `docker-compose.dev.yml` definiert `db` (postgres:15, DB `vereinskiste_test`, User/Passwort `vk`) und `php` (php:8.1-cli mit `pdo_pgsql`, `mbstring`, `gd`, `intl`, `zip`, Composer; Arbeitsverzeichnis `/work` = Repo; Env `VK_TEST_DSN=pgsql:host=db;dbname=vereinskiste_test`, `VK_TEST_USER=vk`, `VK_TEST_PASS=vk`). Tests laufen mit `docker compose -f docker-compose.dev.yml run --rm php sh -c "cd app && composer install && vendor/bin/phpunit"`. Der README dokumentiert das. `.gitignore` enthält `vendor/`, `app/config/config.php`, `app/config/installed.lock`, `app/storage/*` (außer `.gitkeep`), `dist/`, `.superpowers/`.

**Interfaces:**
- Produces: `Connection::fromConfig(array $cfg): \PDO` (Exceptions-Modus, UTF-8); `Migrator::__construct(\PDO $pdo, string $dir)`, `migrate(): array` (angewendete Namen), `pending(): array`, `currentVersion(): ?string`; `Kernel::create(array $config): \Slim\App`; `TestCase::pdo(): \PDO` (frisches leeres Schema pro Test).

- [ ] **Step 1:** Tests `test_applies_pending_migrations_in_order_and_records_them` (zwei temporäre SQL-Dateien → `schema_migrations` enthält beide Namen in Reihenfolge) und `test_failed_migration_rolls_back_whole_step` (gültiges + ungültiges Statement → keine Tabelle angelegt, Name nicht eingetragen).
- [ ] **Step 2:** `cd app && vendor/bin/phpunit tests/Db` → FAIL.
- [ ] **Step 3:** `Migrator` implementieren: Dateien `NNNN_name.sql` sortiert, je Datei eine Transaktion, Tabelle `schema_migrations(name text primary key, applied_at timestamptz)`. Composer: `slim/slim`, `slim/psr7`, `slim/twig-view`, `phpmailer/phpmailer`, `setasign/fpdf`; Dev: `phpunit/phpunit`.
- [ ] **Step 4:** Test `test_health_returns_200_ok_text` (`GET /health`); `Kernel` + `public/index.php` implementieren.
- [ ] **Step 5:** Alle Tests grün; Commit `chore: backend scaffold and migrator`.

### Task 2: Datenbankschema mit Unveränderlichkeit

**Files:**
- Create: `app/migrations/0001_initial.sql`
- Test: `app/tests/Db/SchemaTest.php`

**Interfaces:**
- Produces Tabellen: `users(id, email unique, name, password_hash, active, created_at)`, `user_roles(user_id, role)`, `accounts(id, name, type 'cash'|'bank', opening_balance, opening_date, active)`, `categories(id, name unique, active)`, `bookings(id, account_id, booking_date, amount NUMERIC(12,2) CHECK >0, direction 'in'|'out', category_id null, description, counterparty, receipt_no, kind 'regular'|'correction'|'transfer', status 'active'|'cancelled', correction_of_id null, transfer_id null, version int default 1, created_by, created_at, updated_at)`, `booking_changes(id, booking_id, changed_by, changed_at, changes jsonb, reason NOT NULL)`, `receipts(id, booking_id, original_name, mime, size, sha256, storage_name, uploaded_by, uploaded_at, replaced_at null)`, `settings(key pk, value)`, `audit_log(id, user_id null, action, detail jsonb, at)`.

- [ ] **Step 1:** Tests `test_delete_on_bookings_is_rejected`, `test_update_and_delete_on_booking_changes_is_rejected`, `test_amount_must_be_positive`, `test_booking_changes_requires_non_empty_reason`.
- [ ] **Step 2:** FAIL → **Step 3:** Migration; Trigger `BEFORE DELETE` auf `bookings`, `BEFORE UPDATE OR DELETE` auf `booking_changes` und `audit_log` werfen Exception → **Step 4:** grün; Commit `feat: initial schema with immutability triggers`.

### Task 3: Authentifizierung, Sitzungen, Rollen, CSRF

**Files:**
- Create: `app/src/Auth/{Permissions,UserRepository,AuthService}.php`, `app/src/Http/Middleware/{Session,Csrf,Auth}.php`, `app/src/Web/AuthController.php`, `app/src/Text/de.php`, `app/templates/{layout,auth/login}.twig`
- Test: `app/tests/Auth/PermissionsTest.php`, `app/tests/Web/AuthPagesTest.php`

Zusatz: `app/src/Text/de.php` und die Twig-Funktion `t(string $key): string` entstehen hier (Task 4 erweitert nur die Texte). Erfolgreiche Anmeldungen und fehlgeschlagene Versuche schreiben `audit_log` (Aktion `login` bzw. `login_failed`; bei Fehlversuchen **ohne** E-Mail-Adresse im Detail).

**Interfaces:**
- Produces: `Permissions::for(array $roles): string[]` (Rechte: `bookings.read`, `bookings.write`, `members.read`, `members.write`, `reports.read`, `users.manage`, `settings.manage`); `AuthService::login(string $email, string $password): ?array` (User mit `roles`), `currentUser(): ?array`; Middleware `Auth::requires(string $permission)` (nicht angemeldet → Redirect `/login`, fehlendes Recht → 403); Routen `GET/POST /login`, `POST /logout`; Twig-Funktion `csrf_field()`, `can('permission')`.

- [ ] **Step 1:** Tests `test_kassenpruefer_cannot_write_bookings`, `test_user_with_two_roles_gets_union_of_permissions`, `test_user_without_roles_has_no_permissions`, `test_login_with_wrong_password_shows_error_and_401`, `test_deactivated_user_session_is_rejected`, `test_post_without_csrf_token_is_403`.
- [ ] **Step 2:** FAIL → **Step 3:** `password_hash`/`password_verify`, Session-Cookie `HttpOnly; SameSite=Strict; Secure` (Secure nur bei HTTPS), Session-ID-Rotation beim Login, Rolle/Aktiv-Status bei jedem Request neu aus der DB → **Step 4:** grün; Commit `feat: auth, roles, csrf`.

### Task 4: Layout, Navigation und Konten/Kategorien

**Files:**
- Create: `public/assets/` (Tabler-CSS/JS gebündelt, `app.css`, `app.js`), `app/templates/layout.twig` (Erweiterung), `app/src/Web/{AccountController,CategoryController}.php`, `app/src/Ledger/AccountRepository.php`, `app/templates/admin/{accounts,categories}.twig`, `app/src/Text/de.php`
- Test: `app/tests/Web/AccountsPagesTest.php`

**Interfaces:**
- Produces: Layout mit Seitenleiste (Desktop) bzw. einklappbarer Navigation (Mobil), Einträge nach Berechtigung; Seiten `GET/POST /accounts`, `GET/POST /categories`; Schreiben erfordert `settings.manage`, Lesen `bookings.read`; `AccountRepository::balance(int $accountId, ?string $asOf = null): string` = Anfangssaldo + Einnahmen − Ausgaben (nur `status=active`, Umbuchungen eingerechnet); neue Texte kommen in `app/src/Text/de.php` (Funktion `t()` aus Task 3).

- [ ] **Step 1:** Tests `test_balance_includes_opening_balance_and_ignores_cancelled`, `test_duplicate_category_name_shows_field_error`, `test_navigation_hides_entries_without_permission`, `test_layout_has_viewport_meta_and_mobile_nav_toggle`.
- [ ] **Step 2:** FAIL → **Step 3:** implementieren → **Step 4:** grün; Commit `feat: layout, accounts and categories`.

### Task 5: Buchungen anlegen und auflisten

**Files:**
- Create: `app/src/Ledger/{Money,BookingService,BookingRepository}.php`, `app/src/Web/BookingController.php`, `app/templates/bookings/{list,form,detail}.twig`
- Test: `app/tests/Ledger/MoneyTest.php`, `app/tests/Ledger/BookingServiceTest.php`, `app/tests/Web/BookingsPagesTest.php`

**Interfaces:**
- Produces: `Money::parse(string $input): string` (kanonisch `"12.50"`, akzeptiert `12,5` und `12.50`, wirft `InvalidAmount`); `BookingService::create(array $input, int $userId): array` (wirft `ValidationError` mit Feldnamen); `BookingRepository::find(int $id): ?array`, `search(array $filter, int $limit, int $offset): array` (Filter: `from`, `to`, `account_id`, `category_id`, `direction`, `kind`, `status`, `q`); Seiten `GET /bookings` (Filter per Query, Paginierung, mobil als Karten), `GET/POST /bookings/new`, `GET /bookings/{id}`; Listeneintrag hat `changed` (bool).

- [ ] **Step 1:** Tests `test_parse_rejects_zero_negative_and_three_decimals`, `test_parse_accepts_german_comma`, `test_parse_rejects_above_9999999_99`, `test_create_rejects_inactive_account`, `test_create_sets_created_by_and_version_1`, `test_list_filters_by_period_and_category`, `test_invalid_form_rerenders_with_422_and_keeps_input`.
- [ ] **Step 2:** FAIL → **Step 3:** `Money` mit Integer-Cent intern (kein `bcmath` vorausgesetzt) → **Step 4:** grün; Commit `feat: create and list bookings`.

### Task 6: Änderungsverlauf, Storno, Korrektur, Umbuchung

**Files:**
- Create: `app/src/Ledger/{ChangeLog,TransferService}.php`, `app/templates/bookings/{edit,cancel,correction,transfer}.twig`; Modify: `BookingService.php`, `BookingController.php`, `bookings/detail.twig`
- Test: `app/tests/Ledger/ChangeTest.php`, `app/tests/Web/BookingChangesPagesTest.php`

**Interfaces:**
- Consumes: `BookingService::create`, `BookingRepository::find`.
- Produces: `BookingService::update(int $id, array $fields, string $reason, int $expectedVersion, int $userId): array` (wirft `VersionConflict` → 409, `ValidationError` bei leerem Grund); `cancel(int $id, string $reason, int $expectedVersion, int $userId)`, `restore(...)` gleiche Signatur; `createCorrection(array $input, ?int $correctionOfId, string $reason, int $userId): array`; `TransferService::create(int $fromAccountId, int $toAccountId, string $amount, string $date, string $description, int $userId): array` (zwei verknüpfte Buchungen, `kind='transfer'`, gleiche `transfer_id`); `ChangeLog::record(int $bookingId, int $userId, array $changes, string $reason): void` (`$changes` als `[{field, from, to}]`; wird auch von Task 7 genutzt) und `ChangeLog::forBooking(int $id): array` (`changed_by`, `changed_at`, `changes`, `reason`); Seiten `GET/POST /bookings/{id}/edit`, `POST /bookings/{id}/cancel`, `/restore`, `GET/POST /corrections/new`, `GET/POST /transfers/new`; Bearbeitungsformulare tragen die Version als Hidden-Feld.

- [ ] **Step 1:** Tests `test_update_writes_change_entry_with_old_and_new_value_and_reason`, `test_update_without_reason_is_rejected`, `test_update_with_stale_version_is_409_and_changes_nothing`, `test_cancel_and_restore_each_create_change_entry`, `test_correction_is_visible_booking_of_kind_correction`, `test_transfer_creates_two_linked_bookings`, `test_update_noop_creates_no_entry`, `test_detail_page_shows_history_but_list_does_not`.
- [ ] **Step 2:** FAIL → **Step 3:** Update + Verlauf in einer Transaktion, `version = version + 1`, `WHERE version = :expected`; `kind=transfer`-Buchungen nur über die Umbuchung änderbar → **Step 4:** grün; Commit `feat: change log, cancel, corrections, transfers`.

### Task 7: Belege

**Files:**
- Create: `app/src/Receipts/ReceiptStore.php`, `app/src/Web/ReceiptController.php`; Modify: `bookings/detail.twig`, `bookings/form.twig`
- Test: `app/tests/Receipts/ReceiptStoreTest.php`, `app/tests/Web/ReceiptsPagesTest.php`

**Interfaces:**
- Produces: `ReceiptStore::__construct(\PDO $pdo, string $dir, int $maxBytes = 10485760)`; `add(int $bookingId, string $tmpPath, string $originalName, int $userId): array`; `replace(int $receiptId, string $tmpPath, string $originalName, string $reason, int $userId): array` (Pflichtgrund; alte Datei bleibt, `replaced_at` gesetzt, Verlaufseintrag an der Buchung über `ChangeLog::record`); `add` schreibt ebenfalls einen Verlaufseintrag mit festem Grund „Beleg hinzugefügt". Belege nachreichen (`add`) ist auch nach Jahresabschluss erlaubt, `replace` nicht; `open(int $receiptId): array{path:string,mime:string,name:string}`; Seiten `POST /bookings/{id}/receipts` (multipart; Eingabefeld `accept="image/*,application/pdf"` mit `capture` für die Handykamera), `POST /receipts/{id}/replace`, `GET /receipts/{id}` (Download, nur `bookings.read`).

- [ ] **Step 1:** Tests `test_rejects_executable_renamed_to_pdf` (MIME per `finfo`, nicht Endung), `test_rejects_over_10mb`, `test_rejects_heic_with_clear_message`, `test_rejects_image_above_40_megapixels_without_decoding`, `test_stores_sha256_and_random_storage_name`, `test_replace_keeps_old_file_marked_replaced`, `test_download_requires_permission`.
- [ ] **Step 2:** FAIL → **Step 3:** Dimensionen vor dem Dekodieren per `getimagesize` prüfen; Speichername zufällig, Originalname nie als Pfad → **Step 4:** grün; Commit `feat: receipts`.

### Task 8: Übersicht und Export

**Files:**
- Create: `app/src/Reports/{SummaryService,CsvExporter,PdfExporter}.php`, `app/src/Web/ReportController.php`, `app/templates/reports/summary.twig`
- Test: `app/tests/Reports/SummaryServiceTest.php`, `app/tests/Reports/ExportTest.php`

**Interfaces:**
- Produces: `SummaryService::summary(string $from, string $to, ?int $accountId = null): array` mit `income`, `expense`, `balance`, `byCategory[]` (`category_id`, `name`, `income`, `expense`); zählt nur `status=active`, schließt `kind=transfer` aus, enthält `correction`; `CsvExporter::bookings(array $filter): iterable<string>` (Zeilen gestreamt, UTF-8 mit BOM, Semikolon); `PdfExporter::summary(array $summary, string $clubName): string` (PDF-Bytes); Seiten `GET /reports/summary`, `GET /reports/bookings.csv`, `GET /reports/summary.pdf` (`reports.read`).

- [ ] **Step 1:** Tests `test_summary_excludes_cancelled_and_transfers`, `test_summary_includes_corrections`, `test_csv_is_streamed_for_5000_bookings_within_memory_limit` (`memory_get_peak_usage` < 64 MB), `test_pdf_starts_with_pdf_header_and_contains_totals`.
- [ ] **Step 2:** FAIL → **Step 3:** SQL-Aggregation in der DB, PDF mit FPDF → **Step 4:** grün; Commit `feat: reports and exports`.

### Task 9: Jahresabschluss und Protokoll

**Files:**
- Create: `app/src/Ledger/ClosingService.php`, `app/src/Web/SettingsController.php`, `app/templates/admin/{closing,audit}.twig`; Modify: `BookingService.php`
- Test: `app/tests/Ledger/ClosingTest.php`

**Interfaces:**
- Produces: `ClosingService::closedUntil(): ?string`, `close(string $date, int $userId)`, `reopen(string $date, string $reason, int $userId)` (nur Vorstand, schreibt `audit_log`); `BookingService` lehnt `create`/`update`/`cancel`/`restore` ab, wenn alter oder neuer `booking_date ≤ closedUntil` (`ClosedPeriod` → 409); Seiten `GET/POST /settings/closing`, `GET /audit-log` (`settings.manage`).

- [ ] **Step 1:** Tests `test_create_in_closed_period_is_rejected`, `test_moving_booking_into_closed_period_is_rejected`, `test_edit_of_booking_in_closed_period_is_rejected`, `test_correction_dated_after_closing_is_allowed`, `test_reopen_requires_reason_and_writes_audit_log`, `test_only_vorstand_can_close`.
- [ ] **Step 2:** FAIL → **Step 3:** implementieren → **Step 4:** grün; Commit `feat: closing and audit log`.

### Task 10: Benutzerverwaltung

**Files:**
- Create: `app/src/Web/UserController.php`, `app/templates/admin/{users,user_form,password}.twig`; Modify: `UserRepository.php`
- Test: `app/tests/Web/UsersPagesTest.php`

**Interfaces:**
- Produces: Seiten `GET /users`, `GET/POST /users/new`, `GET/POST /users/{id}/edit` (Name, Rollen, aktiv), `POST /users/{id}/password` (Vorstand setzt neues Passwort), `GET/POST /account/password` (eigenes Passwort: altes + neues); alles `users.manage` außer `/account/password`. Mindestpasswortlänge 10.

- [ ] **Step 1:** Tests `test_last_vorstand_cannot_be_deactivated_or_demoted`, `test_password_shorter_than_10_is_rejected`, `test_duplicate_email_is_rejected`, `test_role_change_is_written_to_audit_log`.
- [ ] **Step 2:** FAIL → **Step 3:** implementieren → **Step 4:** grün; Commit `feat: user management`.

### Task 11: SMTP und Passwort-Reset per Mail

**Files:**
- Create: `app/src/Mail/Mailer.php`, `app/src/Web/PasswordResetController.php`, `app/templates/auth/{reset_request,reset_confirm}.twig`, `app/migrations/0002_password_resets.sql`
- Test: `app/tests/Mail/MailerTest.php`, `app/tests/Web/PasswordResetPagesTest.php`

**Interfaces:**
- Produces: `Mailer::isConfigured(): bool`, `Mailer::send(string $to, string $subject, string $body): void` (PHPMailer SMTP, TLS); Seiten `GET/POST /password-reset` (zeigt immer dieselbe Bestätigung, kein Hinweis auf Existenz), `GET/POST /password-reset/{token}` (einmalig, 1 Stunde gültig, nur Hash gespeichert); Login-Seite zeigt den Link nur bei `isConfigured()`.

- [ ] **Step 1:** Tests `test_request_for_unknown_email_shows_same_page_and_sends_nothing`, `test_token_is_single_use_and_expires_after_one_hour`, `test_without_smtp_config_reset_is_disabled_and_reported`, `test_mailer_never_uses_php_mail` (Transport ist SMTP).
- [ ] **Step 2:** FAIL → **Step 3:** implementieren (Tests mit Fake-Transport) → **Step 4:** grün; Commit `feat: smtp mailer and password reset`.

### Task 12: Web-Installer, Updater, Wartungsmodus

**Files:**
- Create: `app/src/Install/{SystemCheck,Installer,Updater}.php`, `app/templates/install/*.twig`, `app/src/Http/Middleware/Maintenance.php`; Modify: `public/index.php`
- Test: `app/tests/Install/SystemCheckTest.php`, `app/tests/Install/InstallerTest.php`, `app/tests/Install/UpdaterTest.php`

**Interfaces:**
- Produces: `SystemCheck::run(): array` (Einträge `name`, `ok`, `message`; prüft PHP ≥ 8.1, `pdo_pgsql`, `mbstring`, `fileinfo`, `gd`, Schreibrechte auf `config/` und `storage/`, `memory_limit`); `Installer::install(array $db, array $club, array $admin, ?array $smtp): void` (Verbindung testen → Migrationen → erster Vorstand → `config/config.php` schreiben → `config/installed.lock`); `Updater::needsUpdate(): bool`, `Updater::run(bool $backupConfirmed): array`; Middleware `Maintenance` liefert Wartungsseite (503), wenn Code-Version > DB-Version, und leitet auf `/install` um, solange kein `installed.lock` existiert; Seiten `GET/POST /install`, `GET/POST /update`; `Kernel::createInstaller(): \Slim\App` (nur Installer-Routen, kein DB-Zugriff beim Start), die `public/index.php` verwendet, solange `config/config.php` fehlt.

- [ ] **Step 1:** Tests `test_system_check_reports_missing_extension_with_message`, `test_install_creates_admin_with_vorstand_role_and_writes_lock`, `test_install_with_wrong_db_credentials_changes_nothing`, `test_install_is_refused_when_lock_exists`, `test_updater_refuses_without_backup_confirmation`, `test_app_serves_maintenance_page_when_migrations_pending`.
- [ ] **Step 2:** FAIL → **Step 3:** Installer-Seiten ohne Datenbankzugriff renderbar, Passwort des Admins nie loggen → **Step 4:** grün; Commit `feat: web installer, updater, maintenance mode`.

### Task 13: Mobil- und Barrierecheck

**Files:**
- Modify: `public/assets/app.css`, `app/templates/**/*.twig`
- Test: `app/tests/Web/ResponsiveMarkupTest.php`

**Interfaces:**
- Consumes: alle Seiten aus Tasks 3–12.

- [ ] **Step 1:** Tests `test_every_page_has_viewport_meta`, `test_booking_list_renders_card_markup_for_small_screens`, `test_every_form_field_has_label`, `test_every_post_form_contains_csrf_field` (iteriert über alle gerenderten Seiten).
- [ ] **Step 2:** FAIL → **Step 3:** Lücken in Templates und `app.css` schließen (Tabelle→Karten unter 576 px, Touch-Ziele ≥ 44 px, sichtbarer Fokus) → **Step 4:** grün; Commit `feat: responsive and accessibility pass`.

### Task 14: Release-Paket und Dokumentation

**Files:**
- Create: `scripts/build-release.sh`, `scripts/verify-release.sh`, `.github/workflows/ci.yml`; Modify: `README.md`

**Interfaces:**
- Produces: `scripts/build-release.sh <version>` erzeugt `dist/vereinskiste-<version>.zip` mit `app/` (src, templates, vendor ohne Dev, migrations, leere `config/` und `storage/`) und `public/` (index.php, `.htaccess`, `assets/`); CI führt PHPUnit gegen PostgreSQL und den Release-Build aus.

- [ ] **Step 1:** `verify-release.sh` prüft: ZIP enthält `app/vendor/autoload.php`, `public/assets/app.css`, `app/migrations/0001_initial.sql` und **nicht** `app/tests`, `.git`.
- [ ] **Step 2:** Skript ausführen → FAIL (Build-Skript fehlt).
- [ ] **Step 3:** Build-Skript und CI schreiben; README mit Hetzner-Anleitung (Upload, PHP 8 einstellen, PostgreSQL-Datenbank anlegen, `/install` aufrufen, Cronjob optional, Backup vor Update).
- [ ] **Step 4:** `verify-release.sh` grün; Commit `chore: release packaging, ci, docs`.
