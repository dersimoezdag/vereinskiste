# Vereinskiste Phase 1 (Kassenbuch) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein per Web-Installer installierbares Kassenbuch für einen Verein: Konten, Kategorien, Buchungen mit Belegen, lückenloser Änderungsverlauf, Storno/Korrektur/Umbuchung, Jahresabschluss, Übersicht mit CSV/PDF-Export, Rollen.

**Architecture:** PHP-8-Backend (Slim 4, PDO/PostgreSQL, reine SQL-Migrationen) liefert eine JSON-API mit Session-Login. React-+-MUI-SPA wird lokal/CI gebaut und als statische Dateien ausgeliefert. Release-Paket = `app/` (oberhalb des Webroots) + `public/` (Webroot), per FTP hochladbar.

**Tech Stack:** PHP ≥ 8.1, Slim 4, PHPUnit 10, PostgreSQL 15, PHPMailer, FPDF (setasign/fpdf); React 18, TypeScript, Vite, MUI 5, TanStack Query, React Router, Vitest + Testing Library.

**Spec:** `docs/superpowers/specs/2026-10-12-vereinskiste-design.md` (Phase 1 = Kassenbuch; Mitglieder sind Phase 2 und bekommen einen eigenen Plan).

## Global Constraints

- Server: nur PHP und PostgreSQL; kein Node, Docker, SSH oder Shell vorausgesetzt (Hetzner Webhosting S).
- Limits: 1 Datenbank, 1 Cronjob, 2 PHP-Prozesse, 192 MB Memory-Limit, 10 GB Speicher.
- Migrationen sind transaktional; ein fehlgeschlagener Schritt hinterlässt die DB unverändert.
- Eine Installation = ein Verein, kein Mandantenmodell.
- Beträge exakt (`NUMERIC(12,2)`, in PHP als String/Integer-Cent, nie `float`); Währung fest EUR.
- Buchungen werden nie gelöscht; Änderungen erzeugen Verlaufseinträge mit Benutzer, Zeitpunkt, Feld alt→neu, Pflichtgrund; Einträge sind unveränderlich.
- Kassenbuch-Liste zeigt keinen Verlauf, nur Kennzeichen „geändert" mit Link.
- Korrekturbuchung = eigene sichtbare Buchung (Typ `correction`), vor und nach Abschluss möglich.
- Nach „abgeschlossen bis"-Datum sind Buchungen unveränderbar; Wiederöffnen nur Vorstand mit Pflichtgrund + Protokoll.
- Rollen: `vorstand` (alles), `kassenwart` (Buchungen lesen/schreiben, Mitglieder lesen), `schriftfuehrer` (Mitglieder verwalten, Buchungen lesen), `kassenpruefer` (nur lesen inkl. Verlauf/Export); mehrere Rollen je Benutzer.
- Belege: PDF/JPEG/PNG, max. 10 MB, SHA-256 je Datei, außerhalb des Webroots, Zugriff nur über die API mit Rechteprüfung; „ersetzt" bleibt der alten Buchungsversion zugeordnet.
- Mail ausschließlich per SMTP, nie `mail()`; ohne SMTP bleibt die App nutzbar, Mail-Funktionen sind abgeschaltet und das wird angezeigt.
- Sicherheit: CSRF-Schutz, `password_hash`, Session-Härtung, Upload-Prüfung, Ausgabe-Escaping; keine Personendaten in URLs oder Logs.
- Oberfläche deutsch, Texte zentral abgelegt; Lizenz AGPL-3.0.
- Kein Frontend-Build auf dem Server; Release enthält gebaute Assets und `vendor/`.

## Review Focus

1. Betrag `0`, negativ, mehr als 2 Nachkommastellen oder > 9.999.999,99: 422 mit Feldfehler, nichts gespeichert (Task 5).
2. Zwei Benutzer ändern dieselbe Buchung gleichzeitig: zweiter Speichervorgang bekommt 409, überschreibt nicht still (Task 6).
3. Hochgeladene Datei ist umbenannte `.exe`, > 10 MB, HEIC-Handyfoto oder > 40 Megapixel: abgelehnt mit klarer Meldung, kein Speicher-Überlauf bei 192 MB (Task 7).
4. Buchung mit Datum ≤ abgeschlossen-bis anlegen, oder bestehende Buchung in den abgeschlossenen Zeitraum verschieben: abgelehnt (Task 9).
5. Deaktivierter Benutzer mit bestehender Session, Benutzer ohne Rolle, letzter Vorstand wird deaktiviert/herabgestuft: Zugriff verweigert bzw. Aktion abgelehnt (Tasks 3, 12).

---

## Dateistruktur

```
app/                         # oberhalb des Webroots
  composer.json  phpunit.xml  bootstrap.php
  src/
    Db/        Connection.php  Migrator.php
    Http/      Kernel.php  Json.php  Middleware/{Session,Csrf,Auth,Maintenance}.php
    Auth/      Permissions.php  UserRepository.php  AuthService.php
    Ledger/    Money.php  BookingService.php  BookingRepository.php
               ChangeLog.php  ClosingService.php  TransferService.php
    Receipts/  ReceiptStore.php
    Reports/   SummaryService.php  CsvExporter.php  PdfExporter.php
    Install/   SystemCheck.php  Installer.php  Updater.php  templates/
    Mail/      Mailer.php
    Api/       *Controller.php (ein Controller je Ressource)
  migrations/  0001_initial.sql  ...
  tests/
  config/      (config.php wird vom Installer erzeugt)
  storage/receipts/
public/        index.php  .htaccess  app/ (Frontend-Build, nicht im Git)
frontend/      Vite-Projekt (src/, package.json)
scripts/       build-release.sh
LICENSE  README.md
```

Backend-Tests laufen gegen eine echte PostgreSQL (`VK_TEST_DSN`, `VK_TEST_USER`, `VK_TEST_PASS`; Standard: `pgsql:host=localhost;dbname=vereinskiste_test`).

---

### Task 1: Backend-Gerüst und Migrator

**Files:**
- Create: `app/composer.json`, `app/phpunit.xml`, `app/bootstrap.php`, `public/index.php`, `app/src/Db/Connection.php`, `app/src/Db/Migrator.php`, `app/src/Http/Kernel.php`, `app/tests/TestCase.php`, `LICENSE`, `README.md`
- Test: `app/tests/Db/MigratorTest.php`, `app/tests/Http/HealthTest.php`

**Interfaces:**
- Produces: `Connection::fromConfig(array $cfg): \PDO` (Exceptions-Modus, UTF-8); `Migrator::__construct(\PDO $pdo, string $dir)`, `migrate(): array` (Liste angewendeter Namen), `pending(): array`, `currentVersion(): ?string`; `Kernel::create(array $config): \Slim\App`; `TestCase::pdo(): \PDO` (frisches leeres Schema pro Test).

- [ ] **Step 1:** Test `test_applies_pending_migrations_in_order_and_records_them` (zwei temporäre SQL-Dateien → `schema_migrations` enthält beide Namen in Reihenfolge) und `test_failed_migration_rolls_back_whole_step` (Datei mit gültigem + ungültigem Statement → keine Tabelle angelegt, Name nicht eingetragen).
- [ ] **Step 2:** `cd app && vendor/bin/phpunit tests/Db` → FAIL (Klasse fehlt).
- [ ] **Step 3:** `Migrator` implementieren: Dateien `NNNN_name.sql` sortiert, je Datei eine Transaktion, Tabelle `schema_migrations(name text primary key, applied_at timestamptz)`. Composer-Abhängigkeiten: `slim/slim`, `slim/psr7`, `phpmailer/phpmailer`, `setasign/fpdf`; Dev: `phpunit/phpunit`.
- [ ] **Step 4:** Test `test_health_endpoint_returns_ok_json` (`GET /api/health` → 200 `{"status":"ok"}`); `Kernel` + `public/index.php` implementieren.
- [ ] **Step 5:** Alle Tests grün; Commit `chore: backend scaffold and migrator`.

### Task 2: Datenbankschema mit Unveränderlichkeit

**Files:**
- Create: `app/migrations/0001_initial.sql`
- Test: `app/tests/Db/SchemaTest.php`

**Interfaces:**
- Produces Tabellen: `users(id, email unique, name, password_hash, active, created_at)`, `user_roles(user_id, role)`, `accounts(id, name, type 'cash'|'bank', opening_balance, opening_date, active)`, `categories(id, name unique, active)`, `bookings(id, account_id, booking_date, amount NUMERIC(12,2) CHECK >0, direction 'in'|'out', category_id null, description, counterparty, receipt_no, kind 'regular'|'correction'|'transfer', status 'active'|'cancelled', correction_of_id null, transfer_id null, version int default 1, created_by, created_at, updated_at)`, `booking_changes(id, booking_id, changed_by, changed_at, changes jsonb, reason NOT NULL)`, `receipts(id, booking_id, original_name, mime, size, sha256, storage_name, uploaded_by, uploaded_at, replaced_at null)`, `settings(key pk, value)`, `audit_log(id, user_id null, action, detail jsonb, at)`.

- [ ] **Step 1:** Tests: `test_delete_on_bookings_is_rejected`, `test_update_and_delete_on_booking_changes_is_rejected`, `test_amount_must_be_positive`, `test_booking_changes_requires_reason` (leerer String abgelehnt per CHECK).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Migration schreiben; Trigger `BEFORE DELETE` auf `bookings` und `BEFORE UPDATE OR DELETE` auf `booking_changes` und `audit_log` werfen Exception.
- [ ] **Step 4:** Tests grün; Commit `feat: initial schema with immutability triggers`.

### Task 3: Authentifizierung, Sitzungen, Rollen, CSRF

**Files:**
- Create: `app/src/Auth/{Permissions,UserRepository,AuthService}.php`, `app/src/Http/Middleware/{Session,Csrf,Auth}.php`, `app/src/Api/AuthController.php`
- Test: `app/tests/Auth/PermissionsTest.php`, `app/tests/Api/AuthApiTest.php`

**Interfaces:**
- Produces: `Permissions::for(array $roles): string[]` (Rechte: `bookings.read`, `bookings.write`, `members.read`, `members.write`, `reports.read`, `users.manage`, `settings.manage`); `AuthService::login(string $email, string $password): ?array` (User mit `roles`), `AuthService::currentUser(): ?array`; Middleware `Auth::requires(string $permission)`; API `POST /api/login`, `POST /api/logout`, `GET /api/me` (liefert `user`, `permissions`, `csrfToken`).

- [ ] **Step 1:** Tests: `test_kassenpruefer_cannot_write_bookings`, `test_user_with_two_roles_gets_union_of_permissions`, `test_login_with_wrong_password_returns_401`, `test_deactivated_user_session_is_rejected` (nach Login deaktivieren → `/api/me` 401), `test_mutating_request_without_csrf_token_is_403`, `test_user_without_roles_has_no_permissions`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implementieren: `password_hash`/`password_verify`, Session-Cookie `HttpOnly; SameSite=Strict; Secure` (Secure nur bei HTTPS), Session-ID-Rotation beim Login, CSRF per Token im Header `X-CSRF-Token`, Rolle/Aktiv-Status bei jedem Request neu aus der DB.
- [ ] **Step 4:** Grün; Commit `feat: auth, roles, csrf`.

### Task 4: Konten und Kategorien

**Files:**
- Create: `app/src/Api/{AccountController,CategoryController}.php`, `app/src/Ledger/AccountRepository.php`
- Test: `app/tests/Api/AccountsApiTest.php`

**Interfaces:**
- Produces: `GET/POST /api/accounts`, `PATCH /api/accounts/{id}` (Name, aktiv); `GET/POST /api/categories`, `PATCH /api/categories/{id}`. Schreiben erfordert `settings.manage`, Lesen `bookings.read`. `AccountRepository::balance(int $accountId, ?string $asOf = null): string` = Anfangssaldo + Einnahmen − Ausgaben (nur `status=active`, Umbuchungen eingerechnet).

- [ ] **Step 1:** Tests: `test_balance_includes_opening_balance_and_ignores_cancelled`, `test_deactivated_account_rejects_new_bookings` (nutzt Task 5 später; hier nur Flag), `test_duplicate_category_name_is_422`.
- [ ] **Step 2:** FAIL → **Step 3:** implementieren → **Step 4:** grün; Commit `feat: accounts and categories`.

### Task 5: Buchungen anlegen und lesen

**Files:**
- Create: `app/src/Ledger/{Money,BookingService,BookingRepository}.php`, `app/src/Api/BookingController.php`
- Test: `app/tests/Ledger/MoneyTest.php`, `app/tests/Ledger/BookingServiceTest.php`, `app/tests/Api/BookingsApiTest.php`

**Interfaces:**
- Produces: `Money::parse(string $input): string` (kanonisch `"12.50"`, akzeptiert `12,5` und `12.50`, wirft `InvalidAmount`); `BookingService::create(array $input, int $userId): array`; `BookingRepository::find(int $id): ?array`, `search(array $filter, int $limit, int $offset): array` (Filter: `from`, `to`, `account_id`, `category_id`, `direction`, `kind`, `status`, `q`); API `GET /api/bookings`, `POST /api/bookings`, `GET /api/bookings/{id}`; Antwort enthält `changed` (bool: gibt es Verlaufseinträge).

- [ ] **Step 1:** Tests: `test_parse_rejects_zero_negative_and_three_decimals`, `test_parse_accepts_german_comma`, `test_parse_rejects_above_9999999_99`, `test_create_rejects_inactive_account`, `test_create_sets_created_by_and_version_1`, `test_list_filters_by_period_and_category`.
- [ ] **Step 2:** FAIL → **Step 3:** `Money` mit String-Arithmetik (`bcmath` nicht voraussetzen; Integer-Cent intern), `BookingService::create` validiert alle Felder (Fehler als `ValidationError` mit Feldnamen → API 422) → **Step 4:** grün; Commit `feat: create and list bookings`.

### Task 6: Änderungsverlauf, Storno, Korrektur, Umbuchung

**Files:**
- Create: `app/src/Ledger/{ChangeLog,TransferService}.php`; Modify: `BookingService.php`, `BookingController.php`
- Test: `app/tests/Ledger/ChangeTest.php`, `app/tests/Api/BookingChangesApiTest.php`

**Interfaces:**
- Consumes: `BookingService::create`, `BookingRepository::find`.
- Produces: `BookingService::update(int $id, array $fields, string $reason, int $expectedVersion, int $userId): array` (wirft `VersionConflict` → 409, `ValidationError` bei leerem Grund); `cancel(int $id, string $reason, int $expectedVersion, int $userId)`, `restore(...)` gleiche Signatur; `createCorrection(array $input, ?int $correctionOfId, string $reason, int $userId): array`; `TransferService::create(int $fromAccountId, int $toAccountId, string $amount, string $date, string $description, int $userId): array` (zwei verknüpfte Buchungen, `kind='transfer'`, gleiche `transfer_id`); `ChangeLog::forBooking(int $id): array` (Einträge `changed_by`, `changed_at`, `changes` als `[{field, from, to}]`, `reason`); API `PATCH /api/bookings/{id}`, `POST /api/bookings/{id}/cancel`, `/restore`, `GET /api/bookings/{id}/changes`, `POST /api/corrections`, `POST /api/transfers`.

- [ ] **Step 1:** Tests: `test_update_writes_change_entry_with_old_and_new_value_and_reason`, `test_update_without_reason_is_422`, `test_update_with_stale_version_is_409_and_changes_nothing`, `test_cancel_and_restore_each_create_change_entry`, `test_correction_is_visible_booking_of_kind_correction`, `test_transfer_creates_two_linked_bookings`, `test_update_noop_creates_no_entry`.
- [ ] **Step 2:** FAIL → **Step 3:** implementieren; Update + Verlauf in einer Transaktion, `version = version + 1`, `WHERE version = :expected`; `kind=transfer`-Buchungen sind nur über die Umbuchung änderbar → **Step 4:** grün; Commit `feat: change log, cancel, corrections, transfers`.

### Task 7: Belege

**Files:**
- Create: `app/src/Receipts/ReceiptStore.php`, `app/src/Api/ReceiptController.php`
- Test: `app/tests/Receipts/ReceiptStoreTest.php`, `app/tests/Api/ReceiptsApiTest.php`

**Interfaces:**
- Produces: `ReceiptStore::__construct(\PDO $pdo, string $dir, int $maxBytes = 10485760)`; `add(int $bookingId, string $tmpPath, string $originalName, int $userId): array`; `replace(int $receiptId, string $tmpPath, string $originalName, int $userId): array` (alte Datei bleibt, `replaced_at` gesetzt, Verlaufseintrag an der Buchung); `open(int $receiptId): array{path:string,mime:string,name:string}`; API `POST /api/bookings/{id}/receipts` (multipart), `POST /api/receipts/{id}/replace`, `GET /api/receipts/{id}` (Download, nur `bookings.read`).

- [ ] **Step 1:** Tests: `test_rejects_executable_renamed_to_pdf` (MIME per `finfo`, nicht Endung), `test_rejects_over_10mb`, `test_rejects_heic_with_clear_message`, `test_rejects_image_above_40_megapixels_without_decoding`, `test_stores_sha256_and_random_storage_name`, `test_replace_keeps_old_file_marked_replaced`, `test_download_requires_permission`.
- [ ] **Step 2:** FAIL → **Step 3:** Dimensionen vor dem Dekodieren per `getimagesize` prüfen; Speichername zufällig, Original nie als Pfad verwenden → **Step 4:** grün; Commit `feat: receipts`.

### Task 8: Übersicht und Export

**Files:**
- Create: `app/src/Reports/{SummaryService,CsvExporter,PdfExporter}.php`, `app/src/Api/ReportController.php`
- Test: `app/tests/Reports/SummaryServiceTest.php`, `app/tests/Reports/ExportTest.php`

**Interfaces:**
- Produces: `SummaryService::summary(string $from, string $to, ?int $accountId = null): array` mit `income`, `expense`, `balance`, `byCategory[]` (`category_id`, `name`, `income`, `expense`); zählt nur `status=active`, schließt `kind=transfer` aus, enthält `correction`; `CsvExporter::bookings(array $filter): iterable<string>` (Zeilen gestreamt, UTF-8 mit BOM, Semikolon); `PdfExporter::summary(array $summary, string $clubName): string` (PDF-Bytes); API `GET /api/reports/summary`, `/api/reports/bookings.csv`, `/api/reports/summary.pdf` (`reports.read`).

- [ ] **Step 1:** Tests: `test_summary_excludes_cancelled_and_transfers`, `test_summary_includes_corrections`, `test_csv_is_streamed_for_5000_bookings_within_memory_limit` (`memory_get_peak_usage` < 64 MB), `test_pdf_starts_with_pdf_header_and_contains_totals`.
- [ ] **Step 2:** FAIL → **Step 3:** SQL-Aggregation in der DB, PDF mit FPDF → **Step 4:** grün; Commit `feat: reports and exports`.

### Task 9: Jahresabschluss und Protokoll

**Files:**
- Create: `app/src/Ledger/ClosingService.php`, `app/src/Api/SettingsController.php`; Modify: `BookingService.php`
- Test: `app/tests/Ledger/ClosingTest.php`

**Interfaces:**
- Produces: `ClosingService::closedUntil(): ?string`, `close(string $date, int $userId)`, `reopen(string $date, string $reason, int $userId)` (nur Vorstand, schreibt `audit_log`); `BookingService` lehnt `create`/`update`/`cancel`/`restore` ab, wenn alter oder neuer `booking_date ≤ closedUntil` (`ClosedPeriod` → 409); API `GET/PUT /api/settings/closing`, `GET /api/audit-log` (`settings.manage`).

- [ ] **Step 1:** Tests: `test_create_in_closed_period_is_rejected`, `test_moving_booking_into_closed_period_is_rejected`, `test_edit_of_booking_in_closed_period_is_rejected`, `test_correction_dated_after_closing_is_allowed`, `test_reopen_requires_reason_and_writes_audit_log`, `test_only_vorstand_can_close`.
- [ ] **Step 2:** FAIL → **Step 3:** implementieren → **Step 4:** grün; Commit `feat: closing and audit log`.

### Task 10: Benutzerverwaltung

**Files:**
- Create: `app/src/Api/UserController.php`; Modify: `UserRepository.php`
- Test: `app/tests/Api/UsersApiTest.php`

**Interfaces:**
- Produces: `GET/POST /api/users`, `PATCH /api/users/{id}` (Name, Rollen, aktiv), `POST /api/users/{id}/password` (Vorstand setzt neues Passwort), `POST /api/me/password` (altes + neues); alles `users.manage` außer `/me/password`. Mindestpasswortlänge 10.

- [ ] **Step 1:** Tests: `test_last_vorstand_cannot_be_deactivated_or_demoted`, `test_password_shorter_than_10_is_422`, `test_duplicate_email_is_422`, `test_role_change_is_written_to_audit_log`.
- [ ] **Step 2:** FAIL → **Step 3:** implementieren → **Step 4:** grün; Commit `feat: user management`.

### Task 11: SMTP und Passwort-Reset per Mail

**Files:**
- Create: `app/src/Mail/Mailer.php`, `app/src/Api/PasswordResetController.php`; Migration `0002_password_resets.sql`
- Test: `app/tests/Mail/MailerTest.php`, `app/tests/Api/PasswordResetApiTest.php`

**Interfaces:**
- Produces: `Mailer::isConfigured(): bool`, `Mailer::send(string $to, string $subject, string $body): void` (PHPMailer SMTP, TLS); API `POST /api/password-reset/request` (immer 204, kein Hinweis auf Existenz), `POST /api/password-reset/confirm` (Token, einmalig, 1 Stunde gültig, nur Hash gespeichert); `GET /api/me` liefert zusätzlich `mailEnabled`.

- [ ] **Step 1:** Tests: `test_request_for_unknown_email_returns_204_and_sends_nothing`, `test_token_is_single_use_and_expires_after_one_hour`, `test_without_smtp_config_reset_is_disabled_and_reported`, `test_mailer_never_uses_php_mail` (Transport ist SMTP).
- [ ] **Step 2:** FAIL → **Step 3:** implementieren (Tests mit Fake-Transport) → **Step 4:** grün; Commit `feat: smtp mailer and password reset`.

### Task 12: Web-Installer, Updater, Wartungsmodus

**Files:**
- Create: `app/src/Install/{SystemCheck,Installer,Updater}.php`, `app/src/Install/templates/*.php`, `app/src/Http/Middleware/Maintenance.php`; Modify: `public/index.php`
- Test: `app/tests/Install/SystemCheckTest.php`, `app/tests/Install/InstallerTest.php`, `app/tests/Install/UpdaterTest.php`

**Interfaces:**
- Produces: `SystemCheck::run(): array` (Einträge `name`, `ok`, `message`; prüft PHP ≥ 8.1, `pdo_pgsql`, `mbstring`, `fileinfo`, `gd`, Schreibrechte auf `config/` und `storage/`, `memory_limit`); `Installer::install(array $db, array $club, array $admin, ?array $smtp): void` (Verbindung testen → Migrationen → erster Vorstand → `config/config.php` schreiben → `config/installed.lock`); `Updater::needsUpdate(): bool`, `Updater::run(bool $backupConfirmed): array`; Middleware `Maintenance` liefert Wartungsseite (503), wenn Code-Version > DB-Version, und leitet auf `/install` um, solange kein `installed.lock` existiert.

- [ ] **Step 1:** Tests: `test_system_check_reports_missing_extension_with_message`, `test_install_creates_admin_with_vorstand_role_and_writes_lock`, `test_install_with_wrong_db_credentials_changes_nothing`, `test_install_is_refused_when_lock_exists`, `test_updater_refuses_without_backup_confirmation`, `test_app_serves_maintenance_page_when_migrations_pending`.
- [ ] **Step 2:** FAIL → **Step 3:** serverseitig gerenderte, schlichte PHP-Seiten (unabhängig vom React-Build), Passwort des Admins nie loggen → **Step 4:** grün; Commit `feat: web installer, updater, maintenance mode`.

### Task 13: Frontend-Gerüst (React, MUI, Login)

**Files:**
- Create: `frontend/package.json`, `frontend/vite.config.ts`, `frontend/src/{main.tsx,theme.ts,api/client.ts,auth/AuthContext.tsx,layout/Shell.tsx,pages/LoginPage.tsx,i18n/de.ts}`
- Test: `frontend/src/auth/AuthContext.test.tsx`, `frontend/src/api/client.test.ts`

**Interfaces:**
- Produces: `api<T>(path: string, options?: {method?, body?, formData?}): Promise<T>` (fügt CSRF-Header an, wirft `ApiError{status, fields?}`); `useAuth(): {user, permissions, mailEnabled, login(email, pw), logout(), can(permission): boolean}`; Vite-Build nach `../public/app`, `base: './'`.

- [ ] **Step 1:** Tests: `api client sends X-CSRF-Token on POST`, `api client throws ApiError with field errors on 422`, `useAuth.can returns false without permission`, `unauthenticated user is redirected to login`.
- [ ] **Step 2:** `cd frontend && npx vitest run` → FAIL.
- [ ] **Step 3:** Implementieren: MUI-Theme (hell/dunkel nach System), Shell mit Navigation nach Berechtigungen, alle UI-Texte aus `i18n/de.ts`.
- [ ] **Step 4:** Grün; `npm run build` erzeugt `public/app/index.html`; Commit `feat: frontend scaffold with login`.

### Task 14: Frontend Kassenbuch

**Files:**
- Create: `frontend/src/pages/{BookingsPage,BookingDetailPage}.tsx`, `frontend/src/components/{BookingForm,ChangeHistory,ReceiptUpload,CorrectionDialog,TransferDialog}.tsx`
- Test: `frontend/src/pages/BookingsPage.test.tsx`, `frontend/src/components/BookingForm.test.tsx`

**Interfaces:**
- Consumes: Endpunkte aus Tasks 5–7. Produces: Liste mit Filtern und Kennzeichen „geändert" (Link zum Verlauf), Detailansicht mit Verlauf (Benutzer, Zeit, Feld alt→neu, Grund), Änderungsformular mit Pflichtgrund, Beleg-Upload mit Kamera-Eingabe (`accept="image/*,application/pdf" capture`).

- [ ] **Step 1:** Tests: `list shows geändert marker but no history rows`, `BookingForm requires reason when editing`, `BookingForm shows server field errors`, `409 conflict shows reload hint`, `kassenpruefer sees no edit buttons`.
- [ ] **Step 2:** FAIL → **Step 3:** implementieren → **Step 4:** grün; Commit `feat: frontend kassenbuch`.

### Task 15: Frontend Übersicht, Verwaltung, Abschluss

**Files:**
- Create: `frontend/src/pages/{SummaryPage,AccountsPage,CategoriesPage,UsersPage,ClosingPage,AuditLogPage}.tsx`
- Test: `frontend/src/pages/SummaryPage.test.tsx`, `frontend/src/pages/ClosingPage.test.tsx`

**Interfaces:**
- Consumes: Endpunkte aus Tasks 4, 8–10. Produces: Übersicht mit Zeitraum/Konto/Kategorie und Export-Links (CSV, PDF), Verwaltungsseiten nach Rolle, Abschluss mit Pflichtgrund beim Wiederöffnen, Hinweis „Mail nicht konfiguriert", wenn `mailEnabled=false`.

- [ ] **Step 1:** Tests: `summary shows income, expense, balance`, `reopen requires reason`, `users page hidden without users.manage`, `password reset link hidden when mail disabled`.
- [ ] **Step 2:** FAIL → **Step 3:** implementieren → **Step 4:** grün; Commit `feat: frontend reports and administration`.

### Task 16: Release-Paket und Dokumentation

**Files:**
- Create: `scripts/build-release.sh`, `.github/workflows/ci.yml`; Modify: `README.md`
- Test: `scripts/verify-release.sh`

**Interfaces:**
- Produces: `scripts/build-release.sh <version>` erzeugt `dist/vereinskiste-<version>.zip` mit `app/` (src, vendor ohne Dev, migrations, leere `config/` und `storage/`) und `public/` (index.php, `.htaccess`, gebautes Frontend); CI führt Backend-Tests gegen PostgreSQL, Frontend-Tests und Release-Build aus.

- [ ] **Step 1:** `verify-release.sh` prüft: ZIP enthält `app/vendor/autoload.php`, `public/app/index.html`, `app/migrations/0001_initial.sql` und **nicht** `app/tests`, `frontend`, `node_modules`.
- [ ] **Step 2:** Skript ausführen → FAIL (Skript fehlt).
- [ ] **Step 3:** Build-Skript und CI schreiben; README mit Hetzner-Anleitung (Upload, PHP 8 einstellen, PostgreSQL-Datenbank anlegen, `/install` aufrufen, Cronjob optional, Backup vor Update).
- [ ] **Step 4:** `verify-release.sh` grün; Commit `chore: release packaging, ci, docs`.
