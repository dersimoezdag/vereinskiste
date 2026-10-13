# Mitgliederverwaltung Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mitglieder (Personen mit Mitgliedsnummer, Stammdaten und Ein-/Austrittszeiträumen) verwalten; ein Mitglied hat nicht automatisch einen Benutzer, kann aber genau einen haben. Mitglieder können mehrere **Vereinsrollen** tragen (z. B. Ehrenmitglied, Sprecher, Verantwortlicher für …); die Vereinsrollen werden in einem eigenen Reiter unter Einstellungen festgelegt. Manche Vereinsrollen darf nur ein Mitglied tragen (Finanzvorstand, 1./2. Sprecher), andere mehrere (Ehrenmitglied). Als Mitgliedsarten sind Aktives, Passives und Ehemaliges Mitglied vorinstalliert; an der Mitgliedsart soll später der Standard-Mitgliedsbeitrag hängen.

**Architecture:** Neue Tabellen `members` (Stammdaten als `data jsonb`, wie `finance_contacts`) und `membership_periods`; die Verbindung ist die optionale, eindeutige Spalte `users.member_id`. Ein Benutzer ohne Mitglied (z. B. der Admin) und ein Mitglied ohne Benutzer bleiben gültig. Die Verknüpfung vergibt **keine Rechte**: Rechte kommen weiterhin ausschließlich aus `user_roles`. **Vereinsrollen** sind frei definierbare Bezeichnungen (`club_roles`), die Mitgliedern über `member_club_roles` zugeordnet werden, optional mit Zuständigkeit (Freitext, z. B. „Jugend“) und Startdatum. Änderungen laufen über die vorhandene `Foundation\History` (unveränderlich, mit Versionsprüfung).

**Tech Stack:** PHP 8.4, Slim 4, Twig, PostgreSQL 15, PHPUnit (Tests laufen gegen echte PostgreSQL, siehe `tests/TestCase.php`).

## Global Constraints

- Eine Installation = ein Verein; keine `club_id`-Spalten.
- Veröffentlichte Migrationen nicht ändern; neue Migration ist `0011_members.sql` (letzte vorhandene: `0010_bank_import.sql`).
- Benutzer ↔ Mitglied: höchstens **ein** Benutzer pro Mitglied und höchstens **ein** Mitglied pro Benutzer; beides optional.
- Die Verknüpfung ändert weder Rollen noch Rechte, und Austritt eines Mitglieds deaktiviert den Benutzer **nicht** automatisch.
- Rechte: `members.read` zum Ansehen, `members.write` zum Anlegen/Ändern/Ein-/Austritt; Verknüpfen/Lösen zusätzlich `users.manage`. Bestehende Rollenzuordnung bleibt (Schriftführer pflegt, Kassenwart/Kassenprüfer lesen, Admin/Vorstand alles).
- Pflichtgrund nur für Finanzänderungen: Änderungen an Mitgliedern brauchen **keinen** Grund (leer wird als „–“ protokolliert, wie bei Verein/Kontakten).
- Keine Personendaten im allgemeinen Audit-Log (`audit_log`), nur IDs; Daten stehen nur in `foundation_changes`.
- **Vereinsrollen sind reine Bezeichnungen und vergeben keine Rechte.** Sie sind strikt getrennt von den Systemrollen (`user_roles`: admin, vorstand, kassenwart, …). Im UI heißen sie überall „Vereinsrolle“, nie nur „Rolle“.
- Vereinsrollen werden unter Einstellungen → Reiter „Vereinsrollen“ (`/settings/club-roles`, Recht `settings.manage`) angelegt, umbenannt und archiviert; Namen sind ohne Beachtung der Groß-/Kleinschreibung eindeutig. Zuordnung zu Mitgliedern mit `members.write`. Archivierte Rollen bleiben an bisherigen Trägern sichtbar, sind aber nicht mehr neu vergebbar. Zuordnungen werden beendet (Enddatum), nicht gelöscht.
- Jede Vereinsrolle hat `single_holder` (Nur ein Träger gleichzeitig) und eine Art `kind`: `function` (Funktion, z. B. Finanzvorstand) oder `membership_type` (Mitgliedsart). Ein Mitglied hat **höchstens eine** offene Mitgliedsart (Aktiv/Passiv/Ehemalig schließen sich aus); Funktionen darf es beliebig viele tragen. Wechsel der Mitgliedsart = alte Zuweisung beenden, neue anlegen.
- Vorinstalliert (Migration 0011, danach frei umbenenn- und archivierbar): Mitgliedsarten „Aktives Mitglied“, „Passives Mitglied“, „Ehemaliges Mitglied“ (mehrere Träger); Funktionen „Ehrenmitglied“ (mehrere Träger), „Finanzvorstand“, „1. Sprecher“, „2. Sprecher“ (je nur ein Träger).
- Ein Mitglied kann **beliebig viele Vereinsrollen gleichzeitig** tragen (z. B. Aktives Mitglied + Ehrenmitglied + Finanzvorstand). Einzigartig ist nur die Mitgliedsart (höchstens eine offene), und „Nur ein Träger“ beschränkt, wie viele *Mitglieder* eine Rolle haben, nie, wie viele Rollen ein Mitglied hat.
- Austritt am Tag D beendet die Mitgliedsart am Tag D und setzt „Ehemaliges Mitglied“ ab D+1; Eintritt beendet „Ehemaliges Mitglied“ (am Vortag) und setzt „Aktives Mitglied“, falls noch keine Mitgliedsart besteht. Funktionen bleiben unberührt. Die Rollen werden über `club_roles.system_key` (`active_member`, `passive_member`, `former_member`) gefunden, nicht über den Namen; fehlt oder ruht die Rolle, werden Ein-/Austritt trotzdem gespeichert.
- Der Standard-Mitgliedsbeitrag wird **nicht** in diesem Plan gebaut. Er hängt später als eigene Spalte (`club_roles.default_fee`, eigene Migration in Paket 6) an den Mitgliedsarten; das Schema hier ist dafür vorbereitet (`kind = 'membership_type'`), ohne eine ungenutzte Spalte vorzuhalten. Die Mitgliedsart folgt dem Ein- und Austritt automatisch (Task 7).
- Dieselbe Vereinsrolle darf mit verschiedenen Zuständigkeiten an dasselbe Mitglied vergeben werden („Verantwortlich für Jugend“ und „… für Kasse“), aber nicht doppelt mit gleicher Zuständigkeit gleichzeitig.
- Alle sichtbaren Texte deutsch, als Schlüssel in `app/src/Text/de/`; Twig-Ausgaben bleiben automatisch escaped.
- Nicht Teil dieses Plans (bleibt in `docs/plans/ausbau/05-mitglieder-mitgliedschaften.md`, M1-Verträge, M3, M4): Familien-/Einzelverträge, Abteilungen (Vereinsrollen sind keine Abteilungen), Einwilligungen, CSV-Export, Anonymisierung, Beiträge, Mitgliederlogin.

## Bereits umgesetzt (vor diesem Plan)

- „Benutzer“ ist kein Hauptmenüpunkt mehr, sondern ein Reiter unter **Einstellungen** (`templates/components/settings_tabs.twig`, `templates/layout.twig`, `templates/admin/users.twig`, `templates/admin/user_form.twig`). Der Text `settings.users` steht in `app/src/Text/de/core.php`.

## Dateiübersicht

| Datei | Aufgabe |
|---|---|
| `app/migrations/0011_members.sql` | Tabellen `members`, `membership_periods`, Spalte `users.member_id`, `foundation_changes`-Subject `member`, Audit nicht betroffen |
| `app/src/Foundation/History.php` (ändern) | Grund bei `member` optional |
| `app/src/Audit/AuditLog.php` (ändern) | Aktionen `member_user_linked`, `member_user_unlinked` |
| `app/src/Members/MemberRepository.php` | Liste, Suche, Anlegen, Ändern mit Version und Historie |
| `app/src/Members/MembershipPeriods.php` | Eintritt, Austritt, Wiedereintritt ohne Überlappung |
| `app/src/Members/MemberUserLink.php` | Benutzer verknüpfen/lösen, Eindeutigkeit |
| `app/src/Members/MembershipTypeSync.php` | Mitgliedsart folgt Ein-/Austritt (Aktiv → Ehemalig und zurück) |
| `app/src/Members/ClubRoleRepository.php` | Vereinsrollen anlegen, umbenennen, archivieren |
| `app/src/Members/MemberRoleAssignments.php` | Vereinsrollen Mitgliedern zuordnen und beenden |
| `app/src/Web/ClubRoleController.php`, `app/templates/settings/club_roles.twig` | Eigener Reiter „Vereinsrollen“ unter Einstellungen |
| `app/src/Web/MemberController.php` | Liste, Detail, Anlegen/Ändern, Ein-/Austritt, Verknüpfung, Vereinsrollen zuordnen |
| `app/templates/members/index.twig`, `detail.twig` | Oberfläche |
| `app/src/Http/Kernel.php` (ändern) | Routen und Rechte |
| `app/templates/layout.twig`, `components/icons.twig` (ändern) | Menüpunkt „Mitglieder“ |
| `app/src/Web/UserController.php`, `templates/admin/user_form.twig`, `users.twig` (ändern) | Mitglied-Auswahl und Anzeige in der Benutzerverwaltung |
| `app/src/Text/de/members.php` | Neue Texte (wird in der Text-Registrierung ergänzt, siehe Task 1) |
| `app/tests/Members/*.php`, `app/tests/Web/ClubRolesPagesTest.php`, `app/tests/Web/MembersPagesTest.php` | Tests |
| `docs/plans/ausbau/05-mitglieder-mitgliedschaften.md`, `09-umsetzungsstand.md` (ändern) | Plan-/Stand-Doku |

---

### Task 1: Migration, Textdatei, Audit-Aktionen

**Files:**
- Create: `app/migrations/0011_members.sql`
- Create: `app/src/Text/de/members.php`
- Modify: `app/src/Audit/AuditLog.php` (Konstante `ACTIONS`)
- Modify: Stelle, an der `app/src/Text/de/foundation.php` eingebunden wird (suchen mit `grep -rn "foundation.php\|Text/de" app/src`) um `members.php` ergänzen
- Test: `app/tests/Members/SchemaTest.php`

**Interfaces:**
- Produces: Tabellen `members(id, member_no, name, data, version, created_at)`, `membership_periods(id, member_id, start_date, end_date, end_reason)`, `club_roles(id, name, description, active, version)`, `member_club_roles(id, member_id, role_id, scope, start_date, end_date)`, Spalte `users.member_id`; `foundation_changes.subject` erlaubt `'member'` und `'club_role'`.

- [ ] **Step 1: Failing test schreiben** – `app/tests/Members/SchemaTest.php`

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Members;

use PDO;
use PDOException;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Tests\TestCase;

final class SchemaTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = $this->pdo();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();
    }

    public function test_member_numbers_are_unique_and_assigned_automatically(): void
    {
        $this->db->exec("INSERT INTO members(name) VALUES ('A'), ('B')");
        $numbers = $this->db->query('SELECT member_no FROM members ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(2, array_unique($numbers));
    }

    public function test_user_member_link_is_optional_and_one_to_one(): void
    {
        $this->db->exec("INSERT INTO members(name) VALUES ('A')");
        $this->db->exec("INSERT INTO users(name,email,password_hash) VALUES ('U1','u1@example.org','x'), ('U2','u2@example.org','x')");
        $this->db->exec('UPDATE users SET member_id = 1 WHERE id = 1');
        $this->expectException(PDOException::class);
        $this->db->exec('UPDATE users SET member_id = 1 WHERE id = 2');
    }

    public function test_only_one_open_membership_period_and_end_not_before_start(): void
    {
        $this->db->exec("INSERT INTO members(name) VALUES ('A')");
        $this->db->exec("INSERT INTO membership_periods(member_id,start_date) VALUES (1,'2026-01-01')");
        try {
            $this->db->exec("INSERT INTO membership_periods(member_id,start_date) VALUES (1,'2026-02-01')");
            $this->fail('zweiter offener Zeitraum');
        } catch (PDOException) {
            $this->assertTrue(true);
        }
        $this->expectException(PDOException::class);
        $this->db->exec("INSERT INTO membership_periods(member_id,start_date,end_date) VALUES (1,'2026-03-02','2026-03-01')");
    }

    public function test_club_role_names_are_unique_ignoring_case_and_open_assignments_not_duplicated(): void
    {
        $this->db->exec("INSERT INTO members(name) VALUES ('A')");
        $this->db->exec("INSERT INTO member_club_roles(member_id,role_id,scope) VALUES (1,4,'Jugend'), (1,4,'Kasse')");
        foreach (["INSERT INTO club_roles(name) VALUES ('finanzvorstand')",
                  "INSERT INTO member_club_roles(member_id,role_id,scope) VALUES (1,4,'JUGEND')"] as $sql) {
            try {
                $this->db->exec($sql);
                $this->fail($sql);
            } catch (PDOException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_default_club_roles_are_installed_with_kind_and_single_holder(): void
    {
        $rows = $this->db->query('SELECT name, kind, single_holder, system_key FROM club_roles ORDER BY id')->fetchAll();
        $this->assertSame(['active_member', 'passive_member', 'former_member', null, null, null, null], array_column($rows, 'system_key'));
        $this->assertSame(['Aktives Mitglied', 'Passives Mitglied', 'Ehemaliges Mitglied', 'Ehrenmitglied',
            'Finanzvorstand', '1. Sprecher', '2. Sprecher'], array_column($rows, 'name'));
        $this->assertSame(['membership_type', 'membership_type', 'membership_type', 'function', 'function', 'function', 'function'],
            array_column($rows, 'kind'));
        $this->assertSame([false, false, false, false, true, true, true], array_map(fn ($v) => (bool) $v, array_column($rows, 'single_holder')));
    }

    public function test_foundation_history_accepts_member_subject(): void
    {
        $this->db->exec("INSERT INTO users(name,email,password_hash) VALUES ('U','u@example.org','x')");
        $this->db->exec("INSERT INTO foundation_changes(subject,subject_id,actor_id,reason,changes) VALUES ('member',1,1,'–','[]'), ('club_role',1,1,'–','[]')");
        $this->assertSame(2, (int) $this->db->query('SELECT count(*) FROM foundation_changes')->fetchColumn());
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag prüfen**

Run (im Ordner `app`): `php vendor/bin/phpunit tests/Members/SchemaTest.php`
Expected: FAIL (`relation "members" does not exist`).

- [ ] **Step 3: Migration schreiben** – `app/migrations/0011_members.sql`

```sql
-- Mitglieder: Personen mit interner Nummer und zeitlicher Mitgliedschaft.
-- Ein Benutzerkonto ist optional und hoechstens einem Mitglied zugeordnet (users.member_id).

CREATE SEQUENCE member_no_seq;

CREATE TABLE members (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_no  integer     NOT NULL UNIQUE DEFAULT nextval('member_no_seq'),
    name       text        NOT NULL CHECK (length(btrim(name)) BETWEEN 1 AND 200),
    data       jsonb       NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(data) = 'object'),
    version    integer     NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at timestamptz NOT NULL DEFAULT now()
);
ALTER SEQUENCE member_no_seq OWNED BY members.member_no;
CREATE INDEX members_name_idx ON members (lower(name), id);

CREATE TABLE membership_periods (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id  bigint NOT NULL REFERENCES members (id),
    start_date date   NOT NULL,
    end_date   date,
    end_reason text,
    CHECK (end_date IS NULL OR end_date >= start_date)
);
-- Hoechstens ein offener Zeitraum je Mitglied; Ueberlappungen prueft MembershipPeriods unter Zeilensperre.
CREATE UNIQUE INDEX membership_periods_open_idx ON membership_periods (member_id) WHERE end_date IS NULL;
CREATE INDEX membership_periods_member_idx ON membership_periods (member_id, start_date);

ALTER TABLE users ADD COLUMN member_id bigint UNIQUE REFERENCES members (id);

-- Vereinsrollen: frei definierbare Bezeichnungen ohne Rechte (getrennt von user_roles).
CREATE TABLE club_roles (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name        text    NOT NULL CHECK (length(btrim(name)) BETWEEN 1 AND 100),
    description text,
    kind        text    NOT NULL DEFAULT 'function' CHECK (kind IN ('function', 'membership_type')),
    single_holder boolean NOT NULL DEFAULT false,
    system_key  text UNIQUE,
    active      boolean NOT NULL DEFAULT true,
    version     integer NOT NULL DEFAULT 1 CHECK (version > 0)
);
CREATE UNIQUE INDEX club_roles_name_idx ON club_roles (lower(name));

INSERT INTO club_roles (name, kind, single_holder, system_key) VALUES
    ('Aktives Mitglied', 'membership_type', false, 'active_member'),
    ('Passives Mitglied', 'membership_type', false, 'passive_member'),
    ('Ehemaliges Mitglied', 'membership_type', false, 'former_member'),
    ('Ehrenmitglied', 'function', false, NULL),
    ('Finanzvorstand', 'function', true, NULL),
    ('1. Sprecher', 'function', true, NULL),
    ('2. Sprecher', 'function', true, NULL);

CREATE TABLE member_club_roles (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id  bigint NOT NULL REFERENCES members (id),
    role_id    bigint NOT NULL REFERENCES club_roles (id),
    scope      text CHECK (scope IS NULL OR length(btrim(scope)) BETWEEN 1 AND 200),
    start_date date,
    end_date   date,
    CHECK (end_date IS NULL OR start_date IS NULL OR end_date >= start_date)
);
-- Gleiche Rolle mit gleicher Zustaendigkeit nicht doppelt gleichzeitig (offene Zuordnungen).
CREATE UNIQUE INDEX member_club_roles_open_idx
    ON member_club_roles (member_id, role_id, lower(coalesce(scope, ''))) WHERE end_date IS NULL;
CREATE INDEX member_club_roles_role_idx ON member_club_roles (role_id) WHERE end_date IS NULL;

ALTER TABLE foundation_changes DROP CONSTRAINT foundation_changes_subject_check;
ALTER TABLE foundation_changes ADD CONSTRAINT foundation_changes_subject_check
    CHECK (subject IN ('club', 'contact', 'account_bank', 'member', 'club_role'));
```

- [ ] **Step 4: Audit-Aktionen und Texte ergänzen**

In `app/src/Audit/AuditLog.php` die Liste `ACTIONS` um `'member_user_linked', 'member_user_unlinked',` erweitern.

`app/src/Text/de/members.php` (Struktur wie `foundation.php`: ein Array mit Schlüsseln; die genaue Rückgabeform von der Nachbardatei übernehmen):

```php
'nav.members' => 'Mitglieder',
'members.title' => 'Mitglieder',
'members.new' => 'Mitglied anlegen',
'members.search' => 'Name oder Mitgliedsnummer',
'members.no' => 'Nr.',
'members.state.current' => 'Aktuelle Mitglieder',
'members.state.left' => 'Ausgetretene',
'members.state.all' => 'Alle',
'members.field.name' => 'Name',
'members.field.address' => 'Straße und Hausnummer',
'members.field.zip' => 'PLZ',
'members.field.city' => 'Ort',
'members.field.country' => 'Land',
'members.field.email' => 'E-Mail-Adresse',
'members.field.phone' => 'Telefon',
'members.field.birth_date' => 'Geburtsdatum (JJJJ-MM-TT)',
'members.field.notes' => 'Notiz',
'members.period.title' => 'Mitgliedschaft',
'members.period.join' => 'Eintritt',
'members.period.leave' => 'Austritt',
'members.period.start' => 'Eintrittsdatum',
'members.period.end' => 'Austrittsdatum',
'members.period.end_reason' => 'Austrittsgrund (optional)',
'members.period.already_member' => 'Das Mitglied hat bereits eine laufende Mitgliedschaft.',
'members.period.overlap' => 'Der Eintritt muss nach dem letzten Austritt liegen.',
'members.period.not_member' => 'Es gibt keine laufende Mitgliedschaft.',
'members.period.before_start' => 'Der Austritt darf nicht vor dem Eintritt liegen.',
'members.user.title' => 'Benutzerzugang',
'members.user.none' => 'Kein Benutzerzugang. Das Mitglied kann sich nicht anmelden.',
'members.user.link' => 'Benutzer verknüpfen',
'members.user.unlink' => 'Verknüpfung lösen',
'members.user.create' => 'Benutzerzugang anlegen',
'members.user.hint' => 'Die Verknüpfung vergibt keine Rechte; Rollen werden in der Benutzerverwaltung festgelegt.',
'members.user.member_taken' => 'Dieses Mitglied hat bereits einen Benutzer.',
'members.user.user_taken' => 'Dieser Benutzer ist bereits mit einem anderen Mitglied verknüpft.',
'members.user.unknown' => 'Benutzer nicht gefunden.',
'members.user.field' => 'Mitglied (optional)',
'members.user.field.none' => 'Kein Mitglied',
'nav.club_roles' => 'Vereinsrollen',
'clubroles.title' => 'Vereinsrollen',
'clubroles.help' => 'Vereinsrollen (z. B. Ehrenmitglied, Sprecher, Verantwortlich für …) beschreiben Aufgaben im Verein. Sie vergeben keine Rechte in der Anwendung; Zugriffsrechte stehen in der Benutzerverwaltung.',
'clubroles.new' => 'Vereinsrolle anlegen',
'clubroles.name' => 'Bezeichnung',
'clubroles.description' => 'Beschreibung (optional)',
'clubroles.holders' => 'Aktuelle Träger',
'clubroles.archive' => 'Archivieren',
'clubroles.restore' => 'Wieder aktivieren',
'clubroles.archived' => 'Archiviert',
'clubroles.duplicate' => 'Eine Vereinsrolle mit diesem Namen gibt es bereits.',
'clubroles.assign.title' => 'Vereinsrollen',
'clubroles.assign.role' => 'Vereinsrolle',
'clubroles.assign.scope' => 'Zuständigkeit (optional, z. B. Jugend)',
'clubroles.assign.start' => 'Seit (optional)',
'clubroles.assign.add' => 'Vereinsrolle zuweisen',
'clubroles.assign.end' => 'Beenden',
'clubroles.assign.none' => 'Keine Vereinsrolle zugewiesen.',
'clubroles.assign.duplicate' => 'Diese Vereinsrolle mit dieser Zuständigkeit besteht bereits.',
'clubroles.assign.archived' => 'Diese Vereinsrolle ist archiviert und kann nicht neu vergeben werden.',
'clubroles.assign.ended' => 'beendet am',
'clubroles.single' => 'Nur ein Träger gleichzeitig',
'clubroles.single.badge' => 'Nur ein Träger',
'clubroles.kind' => 'Art',
'clubroles.kind.function' => 'Funktion (z. B. Finanzvorstand)',
'clubroles.kind.membership_type' => 'Mitgliedsart (z. B. Aktives Mitglied)',
'clubroles.kind.locked' => 'Die Art kann nicht geändert werden, solange die Vereinsrolle vergeben ist.',
'clubroles.single.conflict' => 'Die Vereinsrolle ist bereits an mehrere Mitglieder vergeben und kann nicht auf „Nur ein Träger“ gestellt werden.',
'clubroles.assign.single_taken' => 'Diese Vereinsrolle kann nur ein Mitglied tragen und ist bereits vergeben. Beende zuerst die bestehende Zuweisung.',
'clubroles.assign.type_exists' => 'Das Mitglied hat bereits eine Mitgliedsart. Beende sie zuerst, um sie zu wechseln.',
'clubroles.assign.type' => 'Mitgliedsart',
'audit.action.member_user_linked' => 'Mitglied mit Benutzer verknüpft',
'audit.action.member_user_unlinked' => 'Verknüpfung Mitglied/Benutzer gelöst',
```

- [ ] **Step 5: Test laufen lassen**

Run: `php vendor/bin/phpunit tests/Members/SchemaTest.php`
Expected: PASS (6 Tests).

- [ ] **Step 6: Commit**

```bash
git add app/migrations/0011_members.sql app/src/Text app/src/Audit/AuditLog.php app/tests/Members/SchemaTest.php
git commit -m "feat: members schema with optional one-to-one user link"
```

---

### Task 2: Historie ohne Pflichtgrund für Mitglieder

**Files:**
- Modify: `app/src/Foundation/History.php` (Zeile mit `in_array($subject, ['club', 'contact'], true)`)
- Test: `app/tests/Members/MemberRepositoryTest.php` (wird in Task 3 angelegt; dieser Test steht dort)

**Interfaces:**
- Produces: `History::record('member'|'club_role', …, '')` speichert den Grund `'–'` statt zu werfen.

- [ ] **Step 1:** In `History.php` die Liste auf `['club', 'contact', 'member', 'club_role']` erweitern.
- [ ] **Step 2:** Diesen Schritt gemeinsam mit Task 3 testen (siehe dort `test_save_creates_member_and_history_without_reason`).
- [ ] **Step 3: Commit** `git commit -am "feat: member history needs no reason"`

---

### Task 3: MemberRepository

**Files:**
- Create: `app/src/Members/MemberRepository.php`
- Test: `app/tests/Members/MemberRepositoryTest.php`

**Interfaces:**
- Produces:
  - `MemberRepository::FIELDS` (Feld → Maximallänge)
  - `list(string $search = '', string $state = 'current', int $page = 1): array` – Zeilen mit `id`, `member_no`, `name`, `data`, `version`, `current` (bool), `user_id` (?int)
  - `find(int $id, bool $lock = false): ?array` – wie `list`, plus `periods` (Liste aller Zeiträume, neueste zuerst)
  - `save(?int $id, array $input, int $version, int $actor, string $reason = ''): int`
- Consumes: `Fields::read`, `Fields::version`, `History`, `Transaction::run`, `Conflict`, `ValidationError`.

- [ ] **Step 1: Failing test** – `app/tests/Members/MemberRepositoryTest.php`

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Members;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\History;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\MemberRepository;
use Vereinskiste\Tests\TestCase;

final class MemberRepositoryTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = $this->pdo();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->db->exec("INSERT INTO users(name,email,password_hash) VALUES ('Admin','admin@example.org','hash')");
    }

    public function test_save_creates_member_and_history_without_reason(): void
    {
        $repo = new MemberRepository($this->db);
        $id = $repo->save(null, ['name' => 'Erika Muster', 'email' => 'erika@example.org', 'birth_date' => '1980-02-29'], 0, 1);
        $member = $repo->find($id);
        $this->assertSame('Erika Muster', $member['name']);
        $this->assertSame(1, $member['member_no']);
        $this->assertSame('1980-02-29', $member['data']['birth_date']);
        $this->assertCount(1, (new History($this->db))->for('member', $id));
    }

    public function test_same_name_and_email_for_different_people_is_allowed(): void
    {
        $repo = new MemberRepository($this->db);
        $a = $repo->save(null, ['name' => 'Max Mustermann', 'email' => 'familie@example.org'], 0, 1);
        $b = $repo->save(null, ['name' => 'Max Mustermann', 'email' => 'familie@example.org'], 0, 1);
        $this->assertNotSame($a, $b);
    }

    public function test_invalid_birth_date_and_stale_version_are_rejected(): void
    {
        $repo = new MemberRepository($this->db);
        foreach (['2026-02-30', '31.12.1990', '2999-01-01'] as $bad) {
            try {
                $repo->save(null, ['name' => 'X', 'birth_date' => $bad], 0, 1);
                $this->fail('Datum ' . $bad);
            } catch (ValidationError $e) {
                $this->assertArrayHasKey('birth_date', $e->errors);
            }
        }
        $id = $repo->save(null, ['name' => 'X'], 0, 1);
        $repo->save($id, ['name' => 'Y'], 1, 1);
        $this->expectException(Conflict::class);
        $repo->save($id, ['name' => 'Z'], 1, 1);
    }

    public function test_list_searches_name_and_number_and_filters_by_state(): void
    {
        $repo = new MemberRepository($this->db);
        $a = $repo->save(null, ['name' => 'Anna Beispiel'], 0, 1);
        $repo->save(null, ['name' => 'Bernd Beispiel'], 0, 1);
        $this->db->exec("INSERT INTO membership_periods(member_id,start_date) VALUES ($a,'2026-01-01')");
        $this->assertCount(2, $repo->list('beispiel', 'all'));
        $this->assertSame(['Anna Beispiel'], array_column($repo->list('', 'current'), 'name'));
        $this->assertSame(['Bernd Beispiel'], array_column($repo->list('2', 'all'), 'name'));
        $this->assertSame([], $repo->list('', 'left'));
    }
}
```

`'left'` = hat mindestens einen Zeitraum, aber keinen offenen. `'current'` = hat einen offenen Zeitraum. Mitglieder ganz ohne Zeitraum erscheinen nur unter `'all'`. Der Test oben stimmt damit überein (Bernd hat keinen Zeitraum).

- [ ] **Step 2: Test laufen lassen**

Run: `php vendor/bin/phpunit tests/Members/MemberRepositoryTest.php`
Expected: FAIL (`Class "Vereinskiste\Members\MemberRepository" not found`).

- [ ] **Step 3: Implementierung** – `app/src/Members/MemberRepository.php`

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Members;

use DateTimeImmutable;
use PDO;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Foundation\History;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

final class MemberRepository
{
    public const FIELDS = ['name' => 200, 'address' => 200, 'zip' => 20, 'city' => 100, 'country' => 100,
        'email' => 254, 'phone' => 50, 'birth_date' => 10, 'notes' => 1000];

    public function __construct(private PDO $pdo) {}

    /** @return list<array<string,mixed>> */
    public function list(string $search = '', string $state = 'current', int $page = 1): array
    {
        $search = mb_substr(trim($search), 0, 200);
        $number = ctype_digit($search) && strlen($search) <= 9 ? (int) $search : null;
        $stmt = $this->pdo->prepare(
            "SELECT m.*, u.id AS user_id,
                    EXISTS (SELECT 1 FROM membership_periods p WHERE p.member_id = m.id AND p.end_date IS NULL) AS current
               FROM members m LEFT JOIN users u ON u.member_id = m.id
              WHERE (m.name ILIKE ? OR m.member_no = ?::integer)
                AND (?::text = 'all'
                     OR (?::text = 'current' AND EXISTS (SELECT 1 FROM membership_periods p WHERE p.member_id = m.id AND p.end_date IS NULL))
                     OR (?::text = 'left' AND EXISTS (SELECT 1 FROM membership_periods p WHERE p.member_id = m.id)
                         AND NOT EXISTS (SELECT 1 FROM membership_periods p WHERE p.member_id = m.id AND p.end_date IS NULL)))
              ORDER BY lower(m.name), m.id LIMIT 50 OFFSET ?"
        );
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $state = in_array($state, ['current', 'left', 'all'], true) ? $state : 'current';
        $stmt->execute([$like, $number, $state, $state, $state, (max(1, min($page, 100000)) - 1) * 50]);
        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    public function find(int $id, bool $lock = false): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.*, u.id AS user_id,
                    EXISTS (SELECT 1 FROM membership_periods p WHERE p.member_id = m.id AND p.end_date IS NULL) AS current
               FROM members m LEFT JOIN users u ON u.member_id = m.id WHERE m.id = ?' . ($lock ? ' FOR UPDATE OF m' : '')
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $member = self::hydrate($row);
        $periods = $this->pdo->prepare('SELECT * FROM membership_periods WHERE member_id = ? ORDER BY start_date DESC, id DESC');
        $periods->execute([$id]);
        $member['periods'] = $periods->fetchAll();
        return $member;
    }

    /** @throws ValidationError|Conflict */
    public function save(?int $id, array $input, int $version, int $actor, string $reason = ''): int
    {
        $data = Fields::read($input, self::FIELDS, ['name']);
        self::checkBirthDate($data['birth_date']);
        return Transaction::run($this->pdo, function () use ($id, $data, $version, $actor, $reason): int {
            $before = $id === null ? null : $this->find($id, true);
            if ($id !== null && ($before === null || $before['version'] !== $version)) {
                throw new Conflict();
            }
            $json = json_encode($data, JSON_THROW_ON_ERROR);
            if ($id === null) {
                $stmt = $this->pdo->prepare('INSERT INTO members (name, data) VALUES (?, ?::jsonb) RETURNING id');
                $stmt->execute([$data['name'], $json]);
                $id = (int) $stmt->fetchColumn();
            } else {
                $this->pdo->prepare('UPDATE members SET name = ?, data = ?::jsonb, version = version + 1 WHERE id = ?')
                    ->execute([$data['name'], $json, $id]);
            }
            (new History($this->pdo))->record('member', $id, $before['data'] ?? [], $data, $actor, $reason);
            return $id;
        });
    }

    private static function checkBirthDate(string $value): void
    {
        if ($value === '') {
            return;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $valid = $date !== false && $date->format('Y-m-d') === $value && $date <= new DateTimeImmutable('today');
        if (!$valid) {
            throw new ValidationError(['birth_date' => 'foundation.invalid']);
        }
    }

    private static function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['member_no'] = (int) $row['member_no'];
        $row['version'] = (int) $row['version'];
        $row['user_id'] = $row['user_id'] === null ? null : (int) $row['user_id'];
        $row['current'] = (bool) $row['current'];
        $row['data'] = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR)
            + array_fill_keys(array_keys(self::FIELDS), '');
        return $row;
    }
}
```

Hinweis: `current` ist in PostgreSQL ein Schlüsselwort, aber als Spaltenalias mit `AS current` zulässig. Falls der Treiber es dennoch ablehnt, Alias in `is_current` umbenennen und überall (`hydrate`, Templates) anpassen.

- [ ] **Step 4: Tests laufen lassen**

Run: `php vendor/bin/phpunit tests/Members/MemberRepositoryTest.php`
Expected: PASS (4 Tests).

- [ ] **Step 5: Commit**

```bash
git add app/src/Members/MemberRepository.php app/tests/Members/MemberRepositoryTest.php app/src/Foundation/History.php
git commit -m "feat: member repository with search, versions and history"
```

---

### Task 4: Ein- und Austritt (Zeiträume)

**Files:**
- Create: `app/src/Members/MembershipPeriods.php`
- Test: `app/tests/Members/MembershipPeriodsTest.php`

**Interfaces:**
- Produces: `MembershipPeriods::join(int $memberId, string $date, int $actor): void`, `leave(int $memberId, string $date, string $reason, int $actor): void`, `isMemberOn(int $memberId, string $date): bool`.
- Regeln: Eintritt/Austritt sind einschließlich. Stichtag D gilt bei `start <= D` und (`end IS NULL` oder `end >= D`). Ein neuer Eintritt muss **nach** dem letzten Austritt liegen. Fehler als `ValidationError(['date' => '<Textschlüssel>'])`.
- Consumes: `MemberRepository::find($id, true)` (Zeilensperre), `History`.

- [ ] **Step 1: Failing test** – `app/tests/Members/MembershipPeriodsTest.php`

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Members;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\MemberRepository;
use Vereinskiste\Members\MembershipPeriods;
use Vereinskiste\Tests\TestCase;

final class MembershipPeriodsTest extends TestCase
{
    private PDO $db;
    private int $member;

    protected function setUp(): void
    {
        $this->db = $this->pdo();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->db->exec("INSERT INTO users(name,email,password_hash) VALUES ('Admin','admin@example.org','hash')");
        $this->member = (new MemberRepository($this->db))->save(null, ['name' => 'Erika Muster'], 0, 1);
    }

    public function test_cutoff_day_is_inclusive_on_both_ends(): void
    {
        $periods = new MembershipPeriods($this->db);
        $periods->join($this->member, '2026-01-10', 1);
        $periods->leave($this->member, '2026-06-30', 'Umzug', 1);
        $this->assertFalse($periods->isMemberOn($this->member, '2026-01-09'));
        $this->assertTrue($periods->isMemberOn($this->member, '2026-01-10'));
        $this->assertTrue($periods->isMemberOn($this->member, '2026-06-30'));
        $this->assertFalse($periods->isMemberOn($this->member, '2026-07-01'));
    }

    public function test_rejoin_starts_new_period_and_keeps_history(): void
    {
        $periods = new MembershipPeriods($this->db);
        $periods->join($this->member, '2026-01-01', 1);
        $periods->leave($this->member, '2026-03-31', '', 1);
        $periods->join($this->member, '2026-04-01', 1);
        $this->assertSame(2, (int) $this->db->query('SELECT count(*) FROM membership_periods')->fetchColumn());
        $this->assertTrue($periods->isMemberOn($this->member, '2026-05-01'));
    }

    public function test_rejects_double_join_overlap_and_invalid_leave(): void
    {
        $periods = new MembershipPeriods($this->db);
        $periods->join($this->member, '2026-01-01', 1);
        $this->assertDateError(fn () => $periods->join($this->member, '2026-02-01', 1), 'members.period.already_member');
        $this->assertDateError(fn () => $periods->leave($this->member, '2025-12-31', '', 1), 'members.period.before_start');
        $periods->leave($this->member, '2026-03-31', '', 1);
        $this->assertDateError(fn () => $periods->join($this->member, '2026-03-31', 1), 'members.period.overlap');
        $this->assertDateError(fn () => $periods->leave($this->member, '2026-04-30', '', 1), 'members.period.not_member');
        $this->assertDateError(fn () => $periods->join($this->member, '2026-02-30', 1), 'foundation.invalid');
    }

    private function assertDateError(callable $call, string $key): void
    {
        try {
            $call();
            $this->fail('Fehler ' . $key . ' erwartet');
        } catch (ValidationError $e) {
            $this->assertSame($key, $e->errors['date']);
        }
    }
}
```

- [ ] **Step 2: Test laufen lassen** – `php vendor/bin/phpunit tests/Members/MembershipPeriodsTest.php` → Expected: FAIL (Klasse fehlt).

- [ ] **Step 3: Implementierung** – `app/src/Members/MembershipPeriods.php`

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Members;

use DateTimeImmutable;
use PDO;
use Vereinskiste\Foundation\History;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

final class MembershipPeriods
{
    public function __construct(private PDO $pdo) {}

    /** @throws ValidationError */
    public function join(int $memberId, string $date, int $actor): void
    {
        self::checkDate($date);
        Transaction::run($this->pdo, function () use ($memberId, $date, $actor): void {
            $this->lockMember($memberId);
            $open = $this->pdo->prepare('SELECT 1 FROM membership_periods WHERE member_id = ? AND end_date IS NULL');
            $open->execute([$memberId]);
            if ($open->fetchColumn() !== false) {
                throw new ValidationError(['date' => 'members.period.already_member']);
            }
            $last = $this->pdo->prepare('SELECT max(end_date) FROM membership_periods WHERE member_id = ?');
            $last->execute([$memberId]);
            $lastEnd = $last->fetchColumn();
            if (is_string($lastEnd) && $date <= $lastEnd) {
                throw new ValidationError(['date' => 'members.period.overlap']);
            }
            $this->pdo->prepare('INSERT INTO membership_periods (member_id, start_date) VALUES (?, ?)')
                ->execute([$memberId, $date]);
            (new History($this->pdo))->record('member', $memberId, ['membership_start' => null], ['membership_start' => $date], $actor, '');
        });
    }

    /** @throws ValidationError */
    public function leave(int $memberId, string $date, string $reason, int $actor): void
    {
        self::checkDate($date);
        $reason = mb_substr(trim($reason), 0, 500);
        Transaction::run($this->pdo, function () use ($memberId, $date, $reason, $actor): void {
            $this->lockMember($memberId);
            $open = $this->pdo->prepare('SELECT id, start_date FROM membership_periods WHERE member_id = ? AND end_date IS NULL');
            $open->execute([$memberId]);
            $period = $open->fetch();
            if ($period === false) {
                throw new ValidationError(['date' => 'members.period.not_member']);
            }
            if ($date < $period['start_date']) {
                throw new ValidationError(['date' => 'members.period.before_start']);
            }
            $this->pdo->prepare('UPDATE membership_periods SET end_date = ?, end_reason = ? WHERE id = ?')
                ->execute([$date, $reason === '' ? null : $reason, $period['id']]);
            (new History($this->pdo))->record('member', $memberId, ['membership_end' => null], ['membership_end' => $date], $actor, $reason);
        });
    }

    public function isMemberOn(int $memberId, string $date): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM membership_periods
            WHERE member_id = ? AND start_date <= ? AND (end_date IS NULL OR end_date >= ?)');
        $stmt->execute([$memberId, $date, $date]);
        return $stmt->fetchColumn() !== false;
    }

    private function lockMember(int $memberId): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM members WHERE id = ? FOR UPDATE');
        $stmt->execute([$memberId]);
        if ($stmt->fetchColumn() === false) {
            throw new ValidationError(['date' => 'foundation.invalid']);
        }
    }

    private static function checkDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new ValidationError(['date' => 'foundation.invalid']);
        }
    }
}
```

- [ ] **Step 4: Tests laufen lassen** – Expected: PASS (3 Tests).
- [ ] **Step 5: Commit** `git add app/src/Members/MembershipPeriods.php app/tests/Members/MembershipPeriodsTest.php && git commit -m "feat: membership periods with inclusive cutoff and no overlap"`

---

### Task 5: Benutzer ↔ Mitglied verknüpfen

**Files:**
- Create: `app/src/Members/MemberUserLink.php`
- Test: `app/tests/Members/MemberUserLinkTest.php`

**Interfaces:**
- Produces: `MemberUserLink::link(int $memberId, int $userId, int $actor): void`, `unlink(int $memberId, int $actor): void`, `linkableUsers(): array` (Benutzer ohne Mitglied: `id`, `name`, `email`), `linkableMembers(): array` (Mitglieder ohne Benutzer: `id`, `member_no`, `name`).
- Fehler als `ValidationError(['user' => '<Textschlüssel>'])`: `members.user.member_taken`, `members.user.user_taken`, `members.user.unknown`.
- Protokoll: `AuditLog` `member_user_linked` / `member_user_unlinked` mit Detail `['member_id' => …, 'user_id' => …]` (nur IDs).
- **Ändert niemals `user_roles`.**

- [ ] **Step 1: Failing test**

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Members;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\MemberRepository;
use Vereinskiste\Members\MemberUserLink;
use Vereinskiste\Tests\TestCase;

final class MemberUserLinkTest extends TestCase
{
    private PDO $db;
    private MemberUserLink $link;

    protected function setUp(): void
    {
        $this->db = $this->pdo();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->db->exec("INSERT INTO users(name,email,password_hash) VALUES
            ('Admin','admin@example.org','x'), ('Erika','erika@example.org','x'), ('Max','max@example.org','x')");
        $this->db->exec("INSERT INTO user_roles(user_id, role) VALUES (2, 'kassenpruefer')");
        $members = new MemberRepository($this->db);
        $members->save(null, ['name' => 'Erika Muster'], 0, 1);
        $members->save(null, ['name' => 'Max Mustermann'], 0, 1);
        $this->link = new MemberUserLink($this->db);
    }

    public function test_link_is_optional_one_to_one_and_does_not_touch_roles(): void
    {
        $this->link->link(1, 2, 1);
        $this->assertSame(2, (int) $this->db->query('SELECT id FROM users WHERE member_id = 1')->fetchColumn());
        $this->assertSame(['kassenpruefer'], $this->db->query('SELECT role FROM user_roles WHERE user_id = 2')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame([['id' => 3, 'name' => 'Max', 'email' => 'max@example.org']],
            array_values(array_filter($this->link->linkableUsers(), fn ($u) => $u['id'] === 3)));
        foreach ([[1, 3, 'members.user.member_taken'], [2, 2, 'members.user.user_taken']] as [$member, $user, $key]) {
            try {
                $this->link->link($member, $user, 1);
                $this->fail($key);
            } catch (ValidationError $e) {
                $this->assertSame($key, $e->errors['user']);
            }
        }
    }

    public function test_unlink_keeps_both_records_and_writes_audit_without_personal_data(): void
    {
        $this->link->link(1, 2, 1);
        $this->link->unlink(1, 1);
        $this->assertNull($this->db->query('SELECT member_id FROM users WHERE id = 2')->fetchColumn() ?: null);
        $this->assertSame(2, (int) $this->db->query('SELECT count(*) FROM members')->fetchColumn());
        $details = $this->db->query("SELECT detail::text FROM audit_log WHERE action LIKE 'member_user_%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(2, $details);
        $this->assertStringNotContainsString('Erika', implode('', $details));
    }

    public function test_unknown_ids_are_rejected(): void
    {
        $this->expectException(ValidationError::class);
        $this->link->link(1, 999, 1);
    }
}
```

- [ ] **Step 2: Test laufen lassen** – Expected: FAIL (Klasse fehlt).

- [ ] **Step 3: Implementierung** – `app/src/Members/MemberUserLink.php`

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Members;

use PDO;
use PDOException;
use Vereinskiste\Audit\AuditLog;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

final class MemberUserLink
{
    public function __construct(private PDO $pdo) {}

    /** @throws ValidationError */
    public function link(int $memberId, int $userId, int $actor): void
    {
        Transaction::run($this->pdo, function () use ($memberId, $userId, $actor): void {
            $member = $this->pdo->prepare('SELECT 1 FROM members WHERE id = ? FOR UPDATE');
            $member->execute([$memberId]);
            $user = $this->pdo->prepare('SELECT member_id FROM users WHERE id = ? FOR UPDATE');
            $user->execute([$userId]);
            $row = $user->fetch();
            if ($member->fetchColumn() === false || $row === false) {
                throw new ValidationError(['user' => 'members.user.unknown']);
            }
            if ($row['member_id'] !== null) {
                throw new ValidationError(['user' => 'members.user.user_taken']);
            }
            $taken = $this->pdo->prepare('SELECT 1 FROM users WHERE member_id = ?');
            $taken->execute([$memberId]);
            if ($taken->fetchColumn() !== false) {
                throw new ValidationError(['user' => 'members.user.member_taken']);
            }
            try {
                $this->pdo->prepare('UPDATE users SET member_id = ? WHERE id = ?')->execute([$memberId, $userId]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23505') { // parallele Verknuepfung: UNIQUE users.member_id
                    throw new ValidationError(['user' => 'members.user.member_taken']);
                }
                throw $e;
            }
            (new AuditLog($this->pdo))->record('member_user_linked', $actor, ['member_id' => $memberId, 'user_id' => $userId]);
        });
    }

    public function unlink(int $memberId, int $actor): void
    {
        Transaction::run($this->pdo, function () use ($memberId, $actor): void {
            $stmt = $this->pdo->prepare('UPDATE users SET member_id = NULL WHERE member_id = ? RETURNING id');
            $stmt->execute([$memberId]);
            $userId = $stmt->fetchColumn();
            if ($userId !== false) {
                (new AuditLog($this->pdo))->record('member_user_unlinked', $actor, ['member_id' => $memberId, 'user_id' => (int) $userId]);
            }
        });
    }

    /** @return list<array{id:int,name:string,email:string}> */
    public function linkableUsers(): array
    {
        $rows = $this->pdo->query('SELECT id, name, email FROM users WHERE member_id IS NULL AND active ORDER BY lower(name)')->fetchAll();
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => $r['name'], 'email' => $r['email']], $rows);
    }

    /** @return list<array{id:int,member_no:int,name:string}> */
    public function linkableMembers(): array
    {
        $rows = $this->pdo->query('SELECT m.id, m.member_no, m.name FROM members m
            WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.member_id = m.id) ORDER BY lower(m.name)')->fetchAll();
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'member_no' => (int) $r['member_no'], 'name' => $r['name']], $rows);
    }
}
```

- [ ] **Step 4: Tests laufen lassen** – Expected: PASS (3 Tests).
- [ ] **Step 5: Commit** `git add app/src/Members/MemberUserLink.php app/tests/Members/MemberUserLinkTest.php && git commit -m "feat: optional one-to-one link between members and users"`

---

### Task 6: Vereinsrollen – Definition und Zuordnung

**Files:**
- Create: `app/src/Members/ClubRoleRepository.php`, `app/src/Members/MemberRoleAssignments.php`
- Test: `app/tests/Members/ClubRolesTest.php`

**Interfaces:**
- Produces:
  - `ClubRoleRepository::list(bool $withArchived = false): array` – je Rolle `id`, `name`, `description`, `kind`, `single_holder`, `active`, `version`, `holders` (Anzahl aktuell offener Zuordnungen)
  - `find(int $id): ?array`
  - `save(?int $id, string $name, string $description, bool $active, int $version, int $actor, bool $singleHolder = false, string $kind = 'function'): int` – Name 1–100 Zeichen, eindeutig ohne Groß-/Kleinschreibung; `$kind` ∈ `function|membership_type`; Fehler `ValidationError(['name' => 'clubroles.duplicate' | 'foundation.required' | 'foundation.invalid', 'kind' => 'clubroles.kind.locked', 'single_holder' => 'clubroles.single.conflict'])`, veraltete Version `Conflict`. Die Art ist nur änderbar, solange keine offene Zuweisung existiert; „Nur ein Träger“ lässt sich nur setzen, wenn höchstens eine offene Zuweisung besteht. Beim Aktualisieren immer beide Werte explizit übergeben (Standardwerte gelten nur für neue Rollen).
  - `MemberRoleAssignments::forMember(int $memberId): array` – Zeilen `id`, `role_id`, `role_name`, `role_active`, `role_kind`, `scope`, `start_date`, `end_date`; offene zuerst
  - `assign(int $memberId, int $roleId, string $scope, ?string $start, int $actor): int`
  - `end(int $assignmentId, string $date, int $actor): void`
  - `holders(int $roleId): array` – Mitglieder mit offener Zuordnung (`member_id`, `member_no`, `name`, `scope`)
- Fehler bei Zuordnung: `ValidationError(['role' => 'clubroles.assign.archived' | 'clubroles.assign.duplicate' | 'clubroles.assign.single_taken' (Rolle mit Nur-ein-Träger ist schon offen vergeben) | 'clubroles.assign.type_exists' (Mitglied hat schon eine offene Mitgliedsart) | 'foundation.invalid'])`. Die Rollenzeile wird dafür mit `FOR UPDATE` gesperrt, damit zwei parallele Zuweisungen einer Nur-ein-Träger-Rolle nicht beide gelingen.
- Protokoll: Rollenänderungen über `History::record('club_role', $roleId, …)`, Zuweisungen über `History::record('member', $memberId, ['club_role' => null], ['club_role' => '<Rollenname> – <Zuständigkeit>'], …)` (Grund leer).
- Consumes: `History`, `Transaction::run`, `Conflict`, `ValidationError`.

- [ ] **Step 1: Failing test** – `app/tests/Members/ClubRolesTest.php`

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Members;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\History;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\ClubRoleRepository;
use Vereinskiste\Members\MemberRepository;
use Vereinskiste\Members\MemberRoleAssignments;
use Vereinskiste\Tests\TestCase;

final class ClubRolesTest extends TestCase
{
    private PDO $db;
    private ClubRoleRepository $roles;
    private MemberRoleAssignments $assignments;
    private int $member;

    protected function setUp(): void
    {
        $this->db = $this->pdo();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->db->exec("INSERT INTO users(name,email,password_hash) VALUES ('Admin','admin@example.org','hash')");
        $this->member = (new MemberRepository($this->db))->save(null, ['name' => 'Erika Muster'], 0, 1);
        $this->roles = new ClubRoleRepository($this->db);
        $this->assignments = new MemberRoleAssignments($this->db);
    }

    public function test_roles_are_unique_ignoring_case_versioned_and_logged(): void
    {
        $id = $this->roles->save(null, 'Beirat', '', true, 0, 1);
        try {
            $this->roles->save(null, 'beirat', '', true, 0, 1);
            $this->fail('Dublette');
        } catch (ValidationError $e) {
            $this->assertSame('clubroles.duplicate', $e->errors['name']);
        }
        try {
            $this->roles->save(null, 'FINANZVORSTAND', '', true, 0, 1);
            $this->fail('Dublette');
        } catch (ValidationError $e) {
            $this->assertSame('clubroles.duplicate', $e->errors['name']);
        }
        $this->roles->save($id, 'Beirat', 'Berät den Vorstand', true, 1, 1);
        $this->assertCount(2, (new History($this->db))->for('club_role', $id));
        $this->expectException(Conflict::class);
        $this->roles->save($id, 'Beirat', 'Veraltet', true, 1, 1);
    }

    public function test_member_can_hold_several_roles_and_one_role_with_different_scopes(): void
    {
        $speaker = $this->roleId('Ehrenmitglied');
        $lead = $this->roles->save(null, 'Verantwortlich für', '', true, 0, 1);
        $this->assignments->assign($this->member, $speaker, '', null, 1);
        $this->assignments->assign($this->member, $lead, 'Jugend', '2026-01-01', 1);
        $this->assignments->assign($this->member, $lead, 'Kasse', null, 1);
        $this->assertCount(3, $this->assignments->forMember($this->member));
        $holders = $this->assignments->holders($lead);
        $this->assertSame(['Jugend', 'Kasse'], array_column($holders, 'scope'));
        $this->assertSame('Erika Muster', $holders[0]['name']);
        try {
            $this->assignments->assign($this->member, $lead, 'jugend', null, 1);
            $this->fail('gleiche Zuständigkeit');
        } catch (ValidationError $e) {
            $this->assertSame('clubroles.assign.duplicate', $e->errors['role']);
        }
    }

    public function test_ending_keeps_the_assignment_and_allows_reassigning(): void
    {
        $speaker = $this->roleId('1. Sprecher');
        $assignment = $this->assignments->assign($this->member, $speaker, '', '2026-01-01', 1);
        $this->assignments->end($assignment, '2026-06-30', 1);
        $this->assertSame([], $this->assignments->holders($speaker));
        $this->assignments->assign($this->member, $speaker, '', '2026-07-01', 1);
        $rows = $this->assignments->forMember($this->member);
        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]['end_date']);
        $this->assertSame('2026-06-30', $rows[1]['end_date']);
    }

    public function test_archived_role_stays_visible_but_cannot_be_assigned(): void
    {
        $id = $this->roles->save(null, 'Kassenprüfer', '', true, 0, 1);
        $this->assignments->assign($this->member, $id, '', null, 1);
        $this->roles->save($id, 'Kassenprüfer', '', false, 1, 1);
        $this->assertSame('Kassenprüfer', $this->assignments->forMember($this->member)[0]['role_name']);
        $this->assertNotContains('Kassenprüfer', array_column($this->roles->list(false), 'name'));
        $this->assertContains('Kassenprüfer', array_column($this->roles->list(true), 'name'));
        $this->expectException(ValidationError::class);
        $this->assignments->assign($this->member, $id, 'Test', null, 1);
    }

    public function test_single_holder_role_can_only_be_held_by_one_member_at_a_time(): void
    {
        $other = (new MemberRepository($this->db))->save(null, ['name' => 'Max Mustermann'], 0, 1);
        $finance = $this->roleId('Finanzvorstand');
        $first = $this->assignments->assign($this->member, $finance, '', '2026-01-01', 1);
        try {
            $this->assignments->assign($other, $finance, '', null, 1);
            $this->fail('zweiter Träger');
        } catch (ValidationError $e) {
            $this->assertSame('clubroles.assign.single_taken', $e->errors['role']);
        }
        $this->assignments->end($first, '2026-12-31', 1);
        $this->assignments->assign($other, $finance, '', '2027-01-01', 1);
        $this->assertSame(['Max Mustermann'], array_column($this->assignments->holders($finance), 'name'));
    }

    public function test_multi_holder_function_allows_several_members(): void
    {
        $other = (new MemberRepository($this->db))->save(null, ['name' => 'Max Mustermann'], 0, 1);
        $honorary = $this->roleId('Ehrenmitglied');
        $this->assignments->assign($this->member, $honorary, '', null, 1);
        $this->assignments->assign($other, $honorary, '', null, 1);
        $this->assertCount(2, $this->assignments->holders($honorary));
    }

    public function test_member_has_at_most_one_membership_type_but_any_number_of_functions(): void
    {
        $active = $this->roleId('Aktives Mitglied');
        $passive = $this->roleId('Passives Mitglied');
        $assignment = $this->assignments->assign($this->member, $active, '', '2026-01-01', 1);
        $this->assignments->assign($this->member, $this->roleId('Ehrenmitglied'), '', null, 1);
        $this->assignments->assign($this->member, $this->roleId('2. Sprecher'), '', null, 1);
        try {
            $this->assignments->assign($this->member, $passive, '', null, 1);
            $this->fail('zweite Mitgliedsart');
        } catch (ValidationError $e) {
            $this->assertSame('clubroles.assign.type_exists', $e->errors['role']);
        }
        $this->assignments->end($assignment, '2026-06-30', 1);
        $this->assignments->assign($this->member, $passive, '', '2026-07-01', 1);
        $this->assertCount(4, $this->assignments->forMember($this->member));
    }

    public function test_kind_and_single_holder_changes_are_guarded_while_assigned(): void
    {
        $id = $this->roles->save(null, 'Beirat', '', true, 0, 1);
        $other = (new MemberRepository($this->db))->save(null, ['name' => 'Max Mustermann'], 0, 1);
        $this->assignments->assign($this->member, $id, '', null, 1);
        $this->assignments->assign($other, $id, '', null, 1);
        try {
            $this->roles->save($id, 'Beirat', '', true, 1, 1, true, 'function');
            $this->fail('Nur-ein-Träger bei zwei Trägern');
        } catch (ValidationError $e) {
            $this->assertSame('clubroles.single.conflict', $e->errors['single_holder']);
        }
        try {
            $this->roles->save($id, 'Beirat', '', true, 1, 1, false, 'membership_type');
            $this->fail('Art ändern');
        } catch (ValidationError $e) {
            $this->assertSame('clubroles.kind.locked', $e->errors['kind']);
        }
    }

    private function roleId(string $name): int
    {
        $stmt = $this->db->prepare('SELECT id FROM club_roles WHERE name = ?');
        $stmt->execute([$name]);
        return (int) $stmt->fetchColumn();
    }

    public function test_assignment_never_changes_system_roles_or_user_rights(): void
    {
        $id = $this->roles->save(null, 'Vorsitzende', '', true, 0, 1);
        $before = $this->db->query('SELECT count(*) FROM user_roles')->fetchColumn();
        $this->assignments->assign($this->member, $id, '', null, 1);
        $this->assertSame($before, $this->db->query('SELECT count(*) FROM user_roles')->fetchColumn());
    }
}
```

- [ ] **Step 2: Test laufen lassen** – `php vendor/bin/phpunit tests/Members/ClubRolesTest.php` → Expected: FAIL (Klassen fehlen).

- [ ] **Step 3: `ClubRoleRepository`** – `app/src/Members/ClubRoleRepository.php`

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Members;

use PDO;
use PDOException;
use Vereinskiste\Auth\UserRepository;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\History;
use Vereinskiste\Ledger\TextInput;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

final class ClubRoleRepository
{
    public function __construct(private PDO $pdo) {}

    public function list(bool $withArchived = false): array
    {
        $rows = $this->pdo->query(
            'SELECT r.*, (SELECT count(*) FROM member_club_roles a WHERE a.role_id = r.id AND a.end_date IS NULL) AS holders
               FROM club_roles r' . ($withArchived ? '' : ' WHERE r.active') . ' ORDER BY lower(r.name)'
        )->fetchAll();
        return array_map(self::hydrate(...), $rows);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT r.*, 0 AS holders FROM club_roles r WHERE r.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::hydrate($row);
    }

    /** @throws ValidationError|Conflict */
    public function save(?int $id, string $name, string $description, bool $active, int $version, int $actor,
        bool $singleHolder = false, string $kind = 'function'): int
    {
        if (!UserRepository::isStorableText($name . $description)) {
            throw new ValidationError(['name' => 'validation.text.encoding']);
        }
        $name = trim($name);
        $description = trim($description);
        if (TextInput::isBlank($name)) {
            throw new ValidationError(['name' => 'foundation.required']);
        }
        if (mb_strlen($name) > 100 || mb_strlen($description) > 500) {
            throw new ValidationError(['name' => 'foundation.invalid']);
        }
        if (!in_array($kind, ['function', 'membership_type'], true)) {
            throw new ValidationError(['kind' => 'foundation.invalid']);
        }
        return Transaction::run($this->pdo, function () use ($id, $name, $description, $active, $version, $actor, $singleHolder, $kind): int {
            $before = null;
            if ($id !== null) {
                $stmt = $this->pdo->prepare('SELECT * FROM club_roles WHERE id = ? FOR UPDATE');
                $stmt->execute([$id]);
                $before = $stmt->fetch();
                if ($before === false || (int) $before['version'] !== $version) {
                    throw new Conflict();
                }
                $open = $this->pdo->prepare('SELECT count(*) FROM member_club_roles WHERE role_id = ? AND end_date IS NULL');
                $open->execute([$id]);
                $openCount = (int) $open->fetchColumn();
                if ($before['kind'] !== $kind && $openCount > 0) {
                    throw new ValidationError(['kind' => 'clubroles.kind.locked']);
                }
                if ($singleHolder && !$before['single_holder'] && $openCount > 1) {
                    throw new ValidationError(['single_holder' => 'clubroles.single.conflict']);
                }
            }
            $text = $description === '' ? null : $description;
            try {
                if ($id === null) {
                    $stmt = $this->pdo->prepare('INSERT INTO club_roles (name, description, kind, single_holder, active) VALUES (?, ?, ?, ?, ?) RETURNING id');
                    $stmt->execute([$name, $text, $kind, $singleHolder ? 'true' : 'false', $active ? 'true' : 'false']);
                    $id = (int) $stmt->fetchColumn();
                } else {
                    $this->pdo->prepare('UPDATE club_roles SET name = ?, description = ?, kind = ?, single_holder = ?, active = ?, version = version + 1 WHERE id = ?')
                        ->execute([$name, $text, $kind, $singleHolder ? 'true' : 'false', $active ? 'true' : 'false', $id]);
                }
            } catch (PDOException $e) {
                if ($e->getCode() === '23505') {
                    throw new ValidationError(['name' => 'clubroles.duplicate']);
                }
                throw $e;
            }
            (new History($this->pdo))->record('club_role', $id,
                $before === null ? [] : ['name' => $before['name'], 'description' => (string) $before['description'],
                    'kind' => $before['kind'], 'single_holder' => (bool) $before['single_holder'], 'active' => (bool) $before['active']],
                ['name' => $name, 'description' => $description, 'kind' => $kind, 'single_holder' => $singleHolder, 'active' => $active], $actor, '');
            return $id;
        });
    }

    private static function hydrate(array $row): array
    {
        return ['id' => (int) $row['id'], 'name' => $row['name'], 'description' => (string) $row['description'],
            'kind' => $row['kind'], 'single_holder' => (bool) $row['single_holder'], 'system_key' => $row['system_key'],
            'active' => (bool) $row['active'], 'version' => (int) $row['version'], 'holders' => (int) $row['holders']];
    }
}
```

- [ ] **Step 4: `MemberRoleAssignments`** – `app/src/Members/MemberRoleAssignments.php`

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Members;

use DateTimeImmutable;
use PDO;
use PDOException;
use Vereinskiste\Auth\UserRepository;
use Vereinskiste\Foundation\History;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

final class MemberRoleAssignments
{
    public function __construct(private PDO $pdo) {}

    public function forMember(int $memberId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.role_id, r.name AS role_name, r.active AS role_active, r.kind AS role_kind, a.scope, a.start_date, a.end_date
               FROM member_club_roles a JOIN club_roles r ON r.id = a.role_id
              WHERE a.member_id = ? ORDER BY (a.end_date IS NOT NULL), lower(r.name), a.id'
        );
        $stmt->execute([$memberId]);
        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['role_id'] = (int) $row['role_id'];
            $row['role_active'] = (bool) $row['role_active'];
            return $row;
        }, $stmt->fetchAll());
    }

    public function holders(int $roleId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.id AS member_id, m.member_no, m.name, a.scope
               FROM member_club_roles a JOIN members m ON m.id = a.member_id
              WHERE a.role_id = ? AND a.end_date IS NULL ORDER BY lower(m.name), lower(coalesce(a.scope, \'\')), a.id'
        );
        $stmt->execute([$roleId]);
        return array_map(static fn (array $r): array => ['member_id' => (int) $r['member_id'],
            'member_no' => (int) $r['member_no'], 'name' => $r['name'], 'scope' => (string) $r['scope']], $stmt->fetchAll());
    }

    /** @throws ValidationError */
    public function assign(int $memberId, int $roleId, string $scope, ?string $start, int $actor): int
    {
        if (!UserRepository::isStorableText($scope) || mb_strlen(trim($scope)) > 200) {
            throw new ValidationError(['role' => 'foundation.invalid']);
        }
        $scope = trim($scope);
        $start = $start === '' ? null : $start;
        if ($start !== null) {
            self::checkDate($start);
        }
        return Transaction::run($this->pdo, function () use ($memberId, $roleId, $scope, $start, $actor): int {
            $member = $this->pdo->prepare('SELECT 1 FROM members WHERE id = ? FOR UPDATE');
            $member->execute([$memberId]);
            $role = $this->pdo->prepare('SELECT name, active, kind, single_holder FROM club_roles WHERE id = ? FOR UPDATE');
            $role->execute([$roleId]);
            $roleRow = $role->fetch();
            if ($member->fetchColumn() === false || $roleRow === false) {
                throw new ValidationError(['role' => 'foundation.invalid']);
            }
            if (!$roleRow['active']) {
                throw new ValidationError(['role' => 'clubroles.assign.archived']);
            }
            if ($roleRow['single_holder']) {
                $taken = $this->pdo->prepare('SELECT 1 FROM member_club_roles WHERE role_id = ? AND end_date IS NULL');
                $taken->execute([$roleId]);
                if ($taken->fetchColumn() !== false) {
                    throw new ValidationError(['role' => 'clubroles.assign.single_taken']);
                }
            }
            if ($roleRow['kind'] === 'membership_type') {
                $type = $this->pdo->prepare("SELECT 1 FROM member_club_roles a JOIN club_roles r ON r.id = a.role_id
                    WHERE a.member_id = ? AND a.end_date IS NULL AND r.kind = 'membership_type'");
                $type->execute([$memberId]);
                if ($type->fetchColumn() !== false) {
                    throw new ValidationError(['role' => 'clubroles.assign.type_exists']);
                }
            }
            try {
                $stmt = $this->pdo->prepare('INSERT INTO member_club_roles (member_id, role_id, scope, start_date) VALUES (?, ?, ?, ?) RETURNING id');
                $stmt->execute([$memberId, $roleId, $scope === '' ? null : $scope, $start]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23505') {
                    throw new ValidationError(['role' => 'clubroles.assign.duplicate']);
                }
                throw $e;
            }
            $label = $roleRow['name'] . ($scope === '' ? '' : ' – ' . $scope);
            (new History($this->pdo))->record('member', $memberId, ['club_role' => null], ['club_role' => $label], $actor, '');
            return (int) $stmt->fetchColumn();
        });
    }

    /** @throws ValidationError */
    public function end(int $assignmentId, string $date, int $actor): void
    {
        self::checkDate($date);
        Transaction::run($this->pdo, function () use ($assignmentId, $date, $actor): void {
            $stmt = $this->pdo->prepare('SELECT a.member_id, a.scope, a.start_date, r.name FROM member_club_roles a
                JOIN club_roles r ON r.id = a.role_id WHERE a.id = ? AND a.end_date IS NULL FOR UPDATE OF a');
            $stmt->execute([$assignmentId]);
            $row = $stmt->fetch();
            if ($row === false || ($row['start_date'] !== null && $date < $row['start_date'])) {
                throw new ValidationError(['role' => 'foundation.invalid']);
            }
            $this->pdo->prepare('UPDATE member_club_roles SET end_date = ? WHERE id = ?')->execute([$date, $assignmentId]);
            $label = $row['name'] . ($row['scope'] === null ? '' : ' – ' . $row['scope']);
            (new History($this->pdo))->record('member', (int) $row['member_id'], ['club_role' => $label], ['club_role' => null], $actor, '');
        });
    }

    private static function checkDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new ValidationError(['role' => 'foundation.invalid']);
        }
    }
}
```

- [ ] **Step 5: Tests laufen lassen** – `php vendor/bin/phpunit tests/Members/ClubRolesTest.php` → Expected: PASS (9 Tests).
- [ ] **Step 6: Commit** `git add app/src/Members app/tests/Members/ClubRolesTest.php && git commit -m "feat: club roles for members with optional scope"`

---

### Task 7: Mitgliedsart folgt Ein- und Austritt

**Files:**
- Create: `app/src/Members/MembershipTypeSync.php`
- Modify: `app/src/Members/MembershipPeriods.php` (`join`, `leave`), `app/src/Members/MemberRoleAssignments.php` (neue Hilfsmethode)
- Test: `app/tests/Members/MembershipTypeSyncTest.php`

**Regeln (verbindlich):**
- **Austritt am Tag D:** Die offene Mitgliedsart (Aktiv oder Passiv) endet am Tag D (nie vor ihrem eigenen Beginn), und „Ehemaliges Mitglied“ beginnt am Tag D+1. Hat das Mitglied schon nur „Ehemaliges Mitglied“, passiert nichts.
- **Eintritt/Wiedereintritt am Tag D:** Ist „Ehemaliges Mitglied“ offen, endet es am Tag D−1 (nie vor dem eigenen Beginn). Hat das Mitglied danach keine offene Mitgliedsart, beginnt „Aktives Mitglied“ am Tag D. Eine bereits gesetzte Mitgliedsart (z. B. „Passives Mitglied“) bleibt unverändert.
- **Funktionen (Ehrenmitglied, Finanzvorstand, Sprecher …) bleiben bei Ein- und Austritt unberührt.** Ob ein Austritt sie beenden soll, entscheidet der Verein manuell.
- Die Rollen werden über den festen Schlüssel `club_roles.system_key` gefunden (`active_member`, `former_member`), nicht über den Namen. Ist die Rolle archiviert oder fehlt sie, wird der Schritt ohne Fehler übersprungen; Ein- und Austritt gelingen trotzdem.
- Alles läuft in der Transaktion von `MembershipPeriods::join/leave`; scheitert die Mitgliedsart, wird auch der Austritt zurückgerollt.

**Interfaces:**
- Produces: `MembershipTypeSync::onJoin(int $memberId, string $date, int $actor): void`, `onLeave(int $memberId, string $date, int $actor): void`.
- Consumes: `MemberRoleAssignments::assign(...)` (Task 6), `History`.

- [ ] **Step 1: Failing test** – `app/tests/Members/MembershipTypeSyncTest.php`

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Members;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Members\MemberRepository;
use Vereinskiste\Members\MemberRoleAssignments;
use Vereinskiste\Members\MembershipPeriods;
use Vereinskiste\Tests\TestCase;

final class MembershipTypeSyncTest extends TestCase
{
    private PDO $db;
    private MembershipPeriods $periods;
    private MemberRoleAssignments $assignments;
    private int $member;

    protected function setUp(): void
    {
        $this->db = $this->pdo();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->db->exec("INSERT INTO users(name,email,password_hash) VALUES ('Admin','admin@example.org','hash')");
        $this->member = (new MemberRepository($this->db))->save(null, ['name' => 'Erika Muster'], 0, 1);
        $this->periods = new MembershipPeriods($this->db);
        $this->assignments = new MemberRoleAssignments($this->db);
    }

    /** @return array<string,array{start:?string,end:?string}> Rollenname => Zeitraum der letzten Zuweisung */
    private function roles(): array
    {
        $out = [];
        foreach ($this->assignments->forMember($this->member) as $row) {
            $out[$row['role_name']] = ['start' => $row['start_date'], 'end' => $row['end_date']];
        }
        return $out;
    }

    public function test_join_sets_active_member_from_the_join_date(): void
    {
        $this->periods->join($this->member, '2026-01-01', 1);
        $this->assertSame(['Aktives Mitglied' => ['start' => '2026-01-01', 'end' => null]], $this->roles());
    }

    public function test_leave_ends_type_on_the_leave_date_and_starts_former_member_next_day(): void
    {
        $this->periods->join($this->member, '2026-01-01', 1);
        $this->periods->leave($this->member, '2026-06-30', 'Umzug', 1);
        $this->assertSame([
            'Ehemaliges Mitglied' => ['start' => '2026-07-01', 'end' => null],
            'Aktives Mitglied' => ['start' => '2026-01-01', 'end' => '2026-06-30'],
        ], $this->roles());
    }

    public function test_rejoin_ends_former_member_and_starts_active_again(): void
    {
        $this->periods->join($this->member, '2026-01-01', 1);
        $this->periods->leave($this->member, '2026-06-30', '', 1);
        $this->periods->join($this->member, '2026-09-01', 1);
        $roles = $this->assignments->forMember($this->member);
        $open = array_values(array_filter($roles, fn ($r) => $r['end_date'] === null));
        $this->assertSame(['Aktives Mitglied'], array_column($open, 'role_name'));
        $former = array_values(array_filter($roles, fn ($r) => $r['role_name'] === 'Ehemaliges Mitglied'));
        $this->assertSame('2026-08-31', $former[0]['end_date']);
    }

    public function test_existing_passive_type_is_kept_on_join_and_replaced_on_leave(): void
    {
        $passive = (int) $this->db->query("SELECT id FROM club_roles WHERE name = 'Passives Mitglied'")->fetchColumn();
        $this->assignments->assign($this->member, $passive, '', '2026-01-01', 1);
        $this->periods->join($this->member, '2026-01-01', 1);
        $this->assertSame(['Passives Mitglied'], array_keys($this->roles()));
        $this->periods->leave($this->member, '2026-03-31', '', 1);
        $this->assertSame('2026-03-31', $this->roles()['Passives Mitglied']['end']);
        $this->assertSame('2026-04-01', $this->roles()['Ehemaliges Mitglied']['start']);
    }

    public function test_functions_stay_untouched_and_missing_roles_do_not_block(): void
    {
        $finance = (int) $this->db->query("SELECT id FROM club_roles WHERE name = 'Finanzvorstand'")->fetchColumn();
        $this->assignments->assign($this->member, $finance, '', '2026-01-01', 1);
        $this->db->exec("UPDATE club_roles SET active = false WHERE system_key = 'former_member'");
        $this->periods->join($this->member, '2026-01-01', 1);
        $this->periods->leave($this->member, '2026-06-30', '', 1);
        $roles = $this->roles();
        $this->assertNull($roles['Finanzvorstand']['end']);
        $this->assertArrayNotHasKey('Ehemaliges Mitglied', $roles);
        $this->assertSame('2026-06-30', $roles['Aktives Mitglied']['end']);
    }
}
```

- [ ] **Step 2: Test laufen lassen** – `php vendor/bin/phpunit tests/Members/MembershipTypeSyncTest.php` → Expected: FAIL (u. a. „Aktives Mitglied“ fehlt nach `join`).

- [ ] **Step 3: Hilfsmethode in `MemberRoleAssignments`** (am Ende der Klasse, vor `checkDate`):

```php
    /**
     * Beendet alle offenen Mitgliedsarten des Mitglieds (optional nur eine Rolle) zum Ende-Datum, nie vor dem
     * eigenen Beginn. Fuer die automatische Mitgliedsart bei Ein-/Austritt.
     *
     * @return list<int> IDs der Rollen, deren Zuweisung beendet wurde
     */
    public function endOpenMembershipTypes(int $memberId, string $endDate, int $actor, ?int $onlyRoleId = null): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.role_id, a.scope, a.start_date, r.name FROM member_club_roles a
               JOIN club_roles r ON r.id = a.role_id
              WHERE a.member_id = ? AND a.end_date IS NULL AND r.kind = 'membership_type'
                AND (?::bigint IS NULL OR a.role_id = ?::bigint) FOR UPDATE OF a"
        );
        $stmt->execute([$memberId, $onlyRoleId, $onlyRoleId]);
        $ended = [];
        foreach ($stmt->fetchAll() as $row) {
            $end = $row['start_date'] !== null && $row['start_date'] > $endDate ? $row['start_date'] : $endDate;
            $this->pdo->prepare('UPDATE member_club_roles SET end_date = ? WHERE id = ?')->execute([$end, $row['id']]);
            (new History($this->pdo))->record('member', $memberId, ['club_role' => $row['name']], ['club_role' => null], $actor, '');
            $ended[] = (int) $row['role_id'];
        }
        return $ended;
    }

    /** Sucht eine aktive Vereinsrolle ueber ihren festen Schluessel (`active_member`, `former_member`, …). */
    public function roleIdByKey(string $key): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM club_roles WHERE system_key = ? AND active');
        $stmt->execute([$key]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }
```

- [ ] **Step 4: `MembershipTypeSync`** – `app/src/Members/MembershipTypeSync.php`

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Members;

use DateTimeImmutable;
use PDO;

/** Haelt die Mitgliedsart (Aktiv/Passiv/Ehemalig) mit Ein- und Austritt in Einklang. Funktionen bleiben unberuehrt. */
final class MembershipTypeSync
{
    private MemberRoleAssignments $assignments;

    public function __construct(private PDO $pdo)
    {
        $this->assignments = new MemberRoleAssignments($pdo);
    }

    public function onLeave(int $memberId, string $date, int $actor): void
    {
        $former = $this->assignments->roleIdByKey('former_member');
        if ($former === null) {
            return;
        }
        $open = $this->openTypeRoleIds($memberId);
        if ($open === [$former]) {
            return;
        }
        $this->assignments->endOpenMembershipTypes($memberId, $date, $actor);
        $next = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
        $this->assignments->assign($memberId, $former, '', $next, $actor);
    }

    public function onJoin(int $memberId, string $date, int $actor): void
    {
        $former = $this->assignments->roleIdByKey('former_member');
        if ($former !== null && in_array($former, $this->openTypeRoleIds($memberId), true)) {
            $before = (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
            $this->assignments->endOpenMembershipTypes($memberId, $before, $actor, $former);
        }
        $active = $this->assignments->roleIdByKey('active_member');
        if ($active !== null && $this->openTypeRoleIds($memberId) === []) {
            $this->assignments->assign($memberId, $active, '', $date, $actor);
        }
    }

    /** @return list<int> */
    private function openTypeRoleIds(int $memberId): array
    {
        $stmt = $this->pdo->prepare("SELECT a.role_id FROM member_club_roles a JOIN club_roles r ON r.id = a.role_id
            WHERE a.member_id = ? AND a.end_date IS NULL AND r.kind = 'membership_type' ORDER BY a.role_id");
        $stmt->execute([$memberId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
```

- [ ] **Step 5: `MembershipPeriods` anbinden.** In `join()` direkt nach dem `History::record(…'membership_start'…)` einfügen: `(new MembershipTypeSync($this->pdo))->onJoin($memberId, $date, $actor);` – in `leave()` direkt nach dem `History::record(…'membership_end'…)`: `(new MembershipTypeSync($this->pdo))->onLeave($memberId, $date, $actor);`. Außerdem `use`-Zeile ist nicht nötig (gleicher Namespace).

- [ ] **Step 6: Tests laufen lassen** – `php vendor/bin/phpunit tests/Members` → Expected: PASS (alle Mitglieder-Tests, auch die aus Task 4: `MembershipPeriodsTest` legt dabei nun nebenbei Mitgliedsarten an; die dortigen Zähler `membership_periods` bleiben unverändert).
- [ ] **Step 7: Commit** `git add app/src/Members app/tests/Members && git commit -m "feat: membership type follows join and leave"`

---

### Task 8: Web – Liste, Detail, Ein-/Austritt, Verknüpfung, Vereinsrollen, Menü

**Files:**
- Create: `app/src/Web/MemberController.php`, `app/src/Web/ClubRoleController.php`, `app/templates/members/index.twig`, `app/templates/members/detail.twig`, `app/templates/settings/club_roles.twig`
- Modify: `app/src/Http/Kernel.php` (nach den `/contacts`-Routen), `app/templates/layout.twig` (Menüpunkt), `app/templates/components/icons.twig` (Icon `members`)
- Test: `app/tests/Web/MembersPagesTest.php`

**Interfaces:**
- Routen (alle mit `Auth::requires(...)`):

| Methode & Pfad | Recht | Aktion |
|---|---|---|
| GET `/members` | `members.read` | Liste (`q`, `state`, `page`) |
| GET `/members/new` | `members.write` | Formular |
| POST `/members/new` | `members.write` | Anlegen |
| GET `/members/{id}` | `members.read` | Detail |
| POST `/members/{id}` | `members.write` | Ändern (mit `version`) |
| POST `/members/{id}/join` | `members.write` | Eintritt (`date`) |
| POST `/members/{id}/leave` | `members.write` | Austritt (`date`, `end_reason`) |
| POST `/members/{id}/user` | `users.manage` | Benutzer verknüpfen (`user_id`) |
| POST `/members/{id}/user/unlink` | `users.manage` | Verknüpfung lösen |
| POST `/members/{id}/club-roles` | `members.write` | Vereinsrolle zuweisen (`role_id`, `scope`, `start_date`) |
| POST `/members/{id}/club-roles/{assignment}/end` | `members.write` | Zuweisung beenden (`date`) |
| GET `/settings/club-roles` | `settings.manage` | Reiter „Vereinsrollen“: Liste inkl. archivierter, Formular zum Anlegen/Umbenennen mit Art (Funktion/Mitgliedsart) und „Nur ein Träger gleichzeitig“ |
| POST `/settings/club-roles` | `settings.manage` | Vereinsrolle anlegen (`name`, `description`) |
| POST `/settings/club-roles/{id}` | `settings.manage` | Umbenennen/Archivieren/Reaktivieren (`name`, `description`, `kind`, `single_holder`, `active`, `version`) |

- Neuer `ClubRoleController` (Muster `ContactController`; Methoden `index`, `create`, `update`; `ValidationError` → 422, `Conflict` → 409) und Template `settings/club_roles.twig` mit `{% include 'components/settings_tabs.twig' %}`, Hilfetext `clubroles.help` und je Rolle den aktuellen Trägern (`MemberRoleAssignments::holders`, verlinkt auf `/members/{id}`). Reiter in `components/settings_tabs.twig`: `{'url': '/settings/club-roles', 'label': 'nav.club_roles', 'active': settings_path == '/settings/club-roles', 'right': 'settings.manage'}`.
- Das Detail zeigt eine Karte „Vereinsrollen“ (`MemberRoleAssignments::forMember`: offene Zuweisungen mit Zuständigkeit und Button „Beenden“, beendete grau mit „beendet am …“; Formular „Vereinsrolle zuweisen“ mit Auswahl der aktiven Vereinsrollen, Zuständigkeit, Datum; Kurzhinweis, dass keine Rechte vergeben werden), die Mitgliedsart steht als eigene Zeile „Mitgliedsart“ (Auswahl nur, wenn keine offene vorhanden, sonst Button „Beenden“), Funktionen darunter; außerdem Stammdaten-Formular, Mitgliedschaftsliste mit Ein-/Austritt-Formular, Benutzerzugang-Karte (mit Text `members.user.hint`; Buttons nur wenn `can('users.manage')`) und Änderungsverlauf (`History::for('member', $id)`, Partial `components/foundation_history.twig` wiederverwenden).
- Menüpunkt in `layout.twig` direkt vor der „Vereinskasse“-Gruppe, sichtbar mit `can('members.read')`, aktiv bei Pfad `/members…`.

- [ ] **Step 1: Failing test** – `app/tests/Web/MembersPagesTest.php`; Aufbau wie `FoundationPagesTest` (gleiche Traits `WebHarness`, gleicher `setUp`). Zusätzlich `app/tests/Web/ClubRolesPagesTest.php` mit: (a) Vorstand legt Vereinsrolle an, Dublette → 422, veraltete Version → 409; (b) Schriftführer bekommt auf `/settings/club-roles` 403, kann aber im Mitglied eine aktive Vereinsrolle zuweisen und beenden; (c) archivierte Vereinsrolle fehlt in der Auswahl der Mitgliedsseite; die vorinstallierten Vereinsrollen sind sichtbar, „Finanzvorstand“ trägt das Kennzeichen „Nur ein Träger“, ein zweiter Träger liefert 422 mit `clubroles.assign.single_taken`; (d) der Reiter „Vereinsrollen“ erscheint nur mit `settings.manage`. Fälle für `MembersPagesTest`:

```php
public function test_schriftfuehrer_maintains_members_kassenwart_reads_only(): void
{
    $this->loginAs(['schriftfuehrer']);
    $response = $this->postForm('/members/new', ['name' => 'Erika Muster', 'email' => 'erika@example.org', 'version' => '0']);
    $this->assertSame('/members/1', $response->getHeaderLine('Location'));
    $this->assertStringContainsString('Erika Muster', (string) $this->get('/members')->getBody());
    $this->assertSame(302, $this->postForm('/members/1/join', ['date' => '2026-01-01'])->getStatusCode());
    $this->assertSame(422, $this->postForm('/members/1/join', ['date' => '2026-02-01'])->getStatusCode());
    $this->assertSame(409, $this->postForm('/members/1', ['name' => 'X', 'version' => '99'])->getStatusCode());
    $this->pdo->exec("UPDATE user_roles SET role='kassenwart'");
    $this->assertSame(200, $this->get('/members/1')->getStatusCode());
    $this->assertSame(403, $this->postForm('/members/1', ['name' => 'Y', 'version' => '1'])->getStatusCode());
    $this->assertSame(403, $this->get('/members/new')->getStatusCode());
}

public function test_linking_needs_users_manage_and_shows_hint_without_user(): void
{
    $this->loginAs(['schriftfuehrer']);
    $this->postForm('/members/new', ['name' => 'Erika Muster', 'version' => '0']);
    $body = (string) $this->get('/members/1')->getBody();
    $this->assertStringContainsString('Kein Benutzerzugang', $body);
    $this->assertSame(403, $this->postForm('/members/1/user', ['user_id' => '1'])->getStatusCode());
    $this->pdo->exec("UPDATE user_roles SET role='vorstand'");
    $this->assertSame('/members/1', $this->postForm('/members/1/user', ['user_id' => '1'])->getHeaderLine('Location'));
    $this->assertSame(1, (int) $this->pdo->query('SELECT member_id FROM users WHERE id = 1')->fetchColumn());
}

public function test_member_menu_entry_depends_on_permission(): void
{
    $this->loginAs(['schriftfuehrer']);
    $this->assertStringContainsString('href="/members"', (string) $this->get('/')->getBody());
}
```

(`loginAs` legt den Benutzer mit ID 1 an; die Rollen-Updates beziehen sich auf diesen Benutzer.)

- [ ] **Step 2: Test laufen lassen** – `php vendor/bin/phpunit tests/Web/MembersPagesTest.php` → Expected: FAIL (404 / Klasse fehlt).

- [ ] **Step 3: Controller** – `app/src/Web/MemberController.php`, Muster von `ContactController` übernehmen (gleicher Konstruktor `private Closure $pdo`, `render()`-Hilfsmethode, `str()`, `body()`, `flash()`, `redirect()`, `notFound()`, `userId()` aus `AdminController`). Verbindliche Eckpunkte:

```php
final class MemberController extends AdminController
{
    public function __construct(private Closure $pdo) {}

    public function index(Request $req, Response $res): Response   // q, state (current|left|all), page lesen wie ContactController::index
    public function create(Request $req, Response $res): Response  // leeres Formular
    public function show(Request $req, Response $res, array $args): Response
    public function save(Request $req, Response $res, array $args = []): Response
    // wie ContactController::save: ValidationError -> 422 mit Fehlern, Conflict -> 409 ('foundation.conflict'),
    // Erfolg -> flash('foundation.saved') und redirect('/members/' . $id)

    public function join(Request $req, Response $res, array $args): Response
    public function leave(Request $req, Response $res, array $args): Response
    // Beide: (new MembershipPeriods($pdo))->join/leave(...); bei ValidationError Detailseite mit Status 422 rendern,
    // sonst flash('foundation.saved') und redirect('/members/' . $id)

    public function linkUser(Request $req, Response $res, array $args): Response
    public function unlinkUser(Request $req, Response $res, array $args): Response
    public function assignRole(Request $req, Response $res, array $args): Response  // MemberRoleAssignments::assign; Fehler -> 422 mit errors['role']
    public function endRole(Request $req, Response $res, array $args): Response     // MemberRoleAssignments::end, Datum Standard: heute
    // MemberUserLink; Fehler 422 mit errors['user'], Erfolg Redirect auf die Detailseite
}
```

Zusätzlich im `render()` für das Detail: `'linkable_users' => $can('users.manage') ? (new MemberUserLink($pdo))->linkableUsers() : []` (Recht über `$req->getAttribute('permissions')` bzw. die in `AdminController` bereits verwendete Prüfung; das dort vorhandene Muster nutzen, nicht neu erfinden).

- [ ] **Step 4: Templates.** `members/index.twig`: `h1`, Suchfeld (`q`), Filter `state` als drei Reiter/Links, Tabelle mit `class="table table-cards"` und `data-label` je `td` (Nr., Name, E-Mail, Status „Mitglied“/„Ausgetreten“/„–“, Symbol „Benutzer“ wenn `user_id`), Link „Mitglied anlegen“ nur bei `can('members.write')`. `members/detail.twig`: Karten (siehe Interfaces). Formularfelder aus `fields` per Schleife mit Label `members.field.<feld>`, `birth_date` als `type="date"`; Felder deaktivieren (`disabled`), wenn nicht `can('members.write')`. CSRF mit `{{ csrf_field() }}` in jedem POST-Formular.

- [ ] **Step 5: Routen und Menü.** In `Kernel.php`:

```php
$members = new \Vereinskiste\Web\MemberController($pdoProvider);
$app->get('/members', [$members, 'index'])->add(Auth::requires('members.read'));
$app->get('/members/new', [$members, 'create'])->add(Auth::requires('members.write'));
$app->post('/members/new', [$members, 'save'])->add(Auth::requires('members.write'));
$app->get('/members/{id:[0-9]+}', [$members, 'show'])->add(Auth::requires('members.read'));
$app->post('/members/{id:[0-9]+}', [$members, 'save'])->add(Auth::requires('members.write'));
$app->post('/members/{id:[0-9]+}/join', [$members, 'join'])->add(Auth::requires('members.write'));
$app->post('/members/{id:[0-9]+}/leave', [$members, 'leave'])->add(Auth::requires('members.write'));
$app->post('/members/{id:[0-9]+}/user', [$members, 'linkUser'])->add(Auth::requires('users.manage'));
$app->post('/members/{id:[0-9]+}/user/unlink', [$members, 'unlinkUser'])->add(Auth::requires('users.manage'));
$app->post('/members/{id:[0-9]+}/club-roles', [$members, 'assignRole'])->add(Auth::requires('members.write'));
$app->post('/members/{id:[0-9]+}/club-roles/{assignment:[0-9]+}/end', [$members, 'endRole'])->add(Auth::requires('members.write'));
$clubRoles = new \Vereinskiste\Web\ClubRoleController($pdoProvider);
$app->get('/settings/club-roles', [$clubRoles, 'index'])->add(Auth::requires('settings.manage'));
$app->post('/settings/club-roles', [$clubRoles, 'create'])->add(Auth::requires('settings.manage'));
$app->post('/settings/club-roles/{id:[0-9]+}', [$clubRoles, 'update'])->add(Auth::requires('settings.manage'));
```

`/members/new` muss **vor** `/members/{id}` stehen (die Regex `[0-9]+` verhindert ohnehin Kollisionen). `icons.twig`: `'members': 'M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0M3 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2'`. `layout.twig` vor der Vereinskasse-Gruppe:

```twig
{% if can('members.read') %}
<li class="nav-item{% if path starts with '/members' %} active{% endif %}">
    <a class="nav-link" href="/members"><span class="nav-link-icon">{{ icons.icon('members') }}</span><span class="nav-link-title">{{ t('nav.members') }}</span></a>
</li>
{% endif %}
```

- [ ] **Step 6: Tests laufen lassen** – `php vendor/bin/phpunit tests/Web/MembersPagesTest.php tests/Web/ResponsiveMarkupTest.php` → Expected: PASS.
- [ ] **Step 7: Commit** `git add app/src/Web/MemberController.php app/templates app/src/Http/Kernel.php app/tests/Web/MembersPagesTest.php && git commit -m "feat: member pages with membership periods and user link"`

---

### Task 9: Benutzerverwaltung kennt Mitglieder

**Files:**
- Modify: `app/src/Web/UserController.php` (`create`, `update`, `showNew`, `showEdit`, `renderForm`), `app/templates/admin/user_form.twig`, `app/templates/admin/users.twig`
- Test: `app/tests/Web/UserMemberLinkPagesTest.php`

**Interfaces:**
- Consumes: `MemberUserLink::link/unlink/linkableMembers`, `UserRepository::create(...): int` (liefert die neue ID).
- Produces: Optionales Formularfeld `member_id` (Auswahl „Kein Mitglied“ + Mitglieder ohne Benutzer, beim Bearbeiten zusätzlich das aktuell verknüpfte) im Benutzerformular; Liste `users.twig` bekommt eine Spalte „Mitglied“ (Name als Link auf `/members/{id}`); `GET /users/new?member=ID` füllt `name` und `email` aus dem Mitglied vor und wählt es vor (dient dem Button „Benutzerzugang anlegen“ auf der Mitgliedsseite).

- [ ] **Step 1: Failing test** – Fälle: (a) POST `/users/new` mit `member_id=1` legt Benutzer an und setzt `users.member_id`; (b) `member_id` eines schon verknüpften Mitglieds → 422 mit Text `members.user.member_taken`, es wird **kein** Benutzer angelegt (alles in einer Transaktion); (c) `GET /users/new?member=1` enthält Name und E-Mail des Mitglieds im Formular; (d) Benutzer bearbeiten ändert Verknüpfung (neu setzen/lösen); (e) Benutzer ohne `member_id` funktioniert unverändert.
- [ ] **Step 2:** Test laufen lassen → FAIL.
- [ ] **Step 3: Implementierung.** In `create` und `update` das Feld `member_id` lesen (`Fields::version`-artig: nur positive Ganzzahl, sonst `null`), die Benutzeranlage/-änderung und `MemberUserLink::link/unlink` in **einer** `Transaction::run` ausführen (beide Klassen verwenden denselben PDO; `Transaction::run` ist verschachtelbar). `ValidationError` aus dem Link auf `errors['member_id']` abbilden.
- [ ] **Step 4:** Tests laufen lassen: `php vendor/bin/phpunit tests/Web tests/Auth tests/Members` → Expected: PASS.
- [ ] **Step 5: Commit** `git commit -am "feat: assign a member when creating or editing a user"`

---

### Task 10: Dokumentation und Abschluss

**Files:**
- Modify: `docs/plans/ausbau/05-mitglieder-mitgliedschaften.md`, `docs/plans/ausbau/09-umsetzungsstand.md`, `README.md` (falls Funktionsliste vorhanden)

- [ ] **Step 1:** In `05-mitglieder-mitgliedschaften.md` die Zeile „Optionale `users.member_id`-Verknüpfung erst für einen späteren Mitgliederlogin nutzen …“ ersetzen durch: „Optionale `users.member_id`-Verknüpfung (eindeutig, nullable) ist umgesetzt: ein Mitglied hat keinen oder genau einen Benutzer; sie verleiht keine Rechte. Mitgliederlogin (eigene Mitgliederrolle) bleibt offen.“ Zusätzlich: „Vereinsrollen (Ehrenmitglied, Sprecher, Verantwortlich für … mit optionaler Zuständigkeit) sind umgesetzt; sie vergeben keine Rechte.“ Die Checkboxen für `members`, `membership_periods`, `member_changes` (über `foundation_changes`), `/members` und die Rollenverbindung `members.read/write` abhaken. Verträge, Abteilungen (M3), Einwilligungen/Export/Anonymisierung (M4) bleiben offen.
- [ ] **Step 1b:** In `docs/plans/ausbau/06-beitraege-forderungen.md` ergänzen: „Der Standard-Mitgliedsbeitrag hängt an der Mitgliedsart (`club_roles` mit `kind = 'membership_type'`: Aktives/Passives/Ehemaliges Mitglied); dafür kommt dort eine eigene Migration (`default_fee`).“
- [ ] **Step 1c:** Im Abschnitt zu Austritt/Wiedereintritt (M1) von `05-mitglieder-mitgliedschaften.md` vermerken: „Die Mitgliedsart (Vereinsrolle mit `kind = membership_type`) folgt Ein- und Austritt automatisch.“
- [ ] **Step 2:** In `09-umsetzungsstand.md` den Abschnitt „Mitgliederverwaltung (lokal, noch nicht veröffentlicht)“ ergänzen: Umfang aus diesem Plan, Hinweis auf Migration 0011 und dass vor dem Upgrade wie bei 0008/0009 Datenbank und Dateien zu sichern sind.
- [ ] **Step 3: Gesamtlauf** – `php vendor/bin/phpunit` → Expected: alle Tests grün (bekannte Voraussetzung: laufende PostgreSQL-Testdatenbank laut `tests/TestCase.php`).
- [ ] **Step 4: Manuelle Prüfung** – Anwendung starten, als Schriftführer Mitglied anlegen und eintreten lassen; als Admin unter Einstellungen → Benutzer einen Benutzer mit Mitglied verknüpfen; Handy-Breite (≤ 375 px) für Liste und Detail prüfen.
- [ ] **Step 5: Commit** `git commit -am "docs: member management status and plan"`

---

## Self-Review

- **Abdeckung:** „Benutzer unter Einstellungen“ → bereits umgesetzt (Abschnitt oben). „Mitgliederverwaltung“ → Tasks 1–6, 8. „Mitglieder haben nicht automatisch einen Benutzer, können aber einen haben“ → nullable, eindeutige `users.member_id` (Task 1), `MemberUserLink` (Task 5), Verknüpfen auf der Mitgliedsseite (Task 6) und im Benutzerformular samt „Benutzerzugang anlegen“ (Task 7). Eigener Pflichtgrund nur für Finanzänderungen → Task 2.
- **Platzhalter:** Tasks 6 (Controller-Rumpf, Templates) und 7 sind absichtlich als Muster-plus-Eckpunkte beschrieben, weil sie `ContactController`/`user_form.twig` fast 1:1 folgen; der Ausführende liest dafür zuerst diese beiden Vorbilder. Alles mit Fachlogik (Task 1–5) steht vollständig im Plan.
- **Typkonsistenz:** `MemberRepository::save(?int, array, int, int, string = ''): int`, `MembershipPeriods::join/leave/isMemberOn`, `MemberUserLink::link/unlink/linkableUsers/linkableMembers` werden in den späteren Tasks mit denselben Namen verwendet. Fehlerschlüssel: `date` (Zeiträume), `user` (Verknüpfung), `member_id` (Benutzerformular).
- **Vereinsrollen:** Schema und Texte in Task 1, Fachlogik in Task 6, Reiter `/settings/club-roles` und Zuweisung im Mitglied in Task 7. Strikt getrennt von Systemrollen (`user_roles`); ein Test stellt sicher, dass eine Zuweisung `user_roles` nicht berührt.
- **Austritt → Ehemaliges Mitglied (bestätigt):** Task 7 mit eigenem Test; Eintritt setzt Aktives Mitglied.
- **Mitgliedsarten/Nur-ein-Träger:** Schema Task 1 (`kind`, `single_holder`, Vorinstallation), Regeln und Tests Task 6, Verwaltung im Reiter Task 7. Beitrag je Mitgliedsart bewusst nicht enthalten (Paket 6).
- **Offene Entscheidungen für den Auftraggeber:** (1) Soll ein Austritt den Benutzerzugang später optional sperren? Der Plan lässt ihn bewusst unberührt. (2) Eigene Systemrolle „Mitglied“ für einen Mitgliederlogin ist nicht enthalten. (3) Ein Austritt beendet nur die Mitgliedsart, nicht die Funktionen (Finanzvorstand, Sprecher …). Soll das später automatisch geschehen oder warnen? (4) Filtern/Exportieren nach Vereinsrolle (z. B. „alle Sprecher“) fehlt bewusst; die Träger sieht man je Rolle im Reiter.
