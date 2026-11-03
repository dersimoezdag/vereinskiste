# Mitgliedsantrag: Erklärungen mit digitaler Unterschrift – Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Der öffentliche Mitgliedsantrag wird ein Stepper mit vom Vorstand frei festlegbaren Erklärungen (Text per WYSIWYG, Art, Unterschrift ja/nein), digitaler Unterschrift per Maus/Touch, Antragsansicht mit Unterschriften und PDF im Stil des Papierformulars.

**Architecture:** Neue versionierte Tabellen für Erklärungen und je Antrag gespeicherte Erklärungen mit Snapshot und PNG-Unterschrift (`application_signatures`). PHP-Bausteine im Namespace `Vereinskiste\Members` (`Declarations`, `SignatureImage`, `ApplicationSignatures`, `ApplicationPdf`), ein Einstellungs-Controller, zwei Vanilla-JS-Dateien (`signature-pad.js`, `application-stepper.js`). Das Formular bleibt ein einziges `<form>`; der Server prüft einmal beim Absenden.

**Tech Stack:** PHP 8.4, Slim 4, Twig, PostgreSQL 15, GD, HTMLPurifier (`ClubDocuments\Content`), Dompdf 3.1, Tailwind 4, Vanilla JS, PHPUnit, node:test + jsdom.

Spec: `docs/superpowers/specs/2026-11-03-mitgliedsantrag-erklaerungen-design.md`

## Global Constraints

- Branch: `feat/antrag-erklaerungen`. Nie auf `main` committen.
- PHP-Tests: `docker compose -f docker-compose.dev.yml run --rm -T php sh -c "cd app && vendor/bin/phpunit <pfad>"` (nur ein Testlauf gleichzeitig – alle nutzen dieselbe Testdatenbank).
- JS-Tests: `npm test --prefix tests` (einzeln: `node --test tests/<datei>.test.cjs`).
- CSS nach Template-/Klassenänderungen: `node scripts/build-css.cjs`.
- CSP: `script-src 'self'`, keine Inline-Skripte, keine externen Bibliotheken.
- Alle sichtbaren Texte über den Katalog `t('…')`; neue Schlüssel in `app/src/Text/de/declarations.php` (in `app/src/Text/de.php` eingebunden). `CatalogueTest` verlangt, dass jeder literal verwendete Schlüssel existiert und kein Schlüssel doppelt vorkommt.
- Audit-Aktionen brauchen ein Label `audit.action.<name>`.
- Grenzen: Titel 200 Zeichen, Text 20 000 Zeichen, Ort 100 Zeichen, Unterschrift ≤ 200 000 Bytes, ≤ 1200 × 400 px.
- Arten: `confirmation`, `consent`, `notice`. Platzhalter `{verein}`.
- Commit-Nachrichten enden mit `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Dateien

| Datei | Verantwortung |
|---|---|
| `app/migrations/0022_application_declarations.sql` | Tabellen, Trigger, `member_consents`-Erweiterung, Standardsatz |
| `app/src/Members/Declarations.php` | Erklärungen lesen, versioniert speichern, sortieren, (de)aktivieren, Fingerprint, Platzhalter |
| `app/src/Members/SignatureImage.php` | Data-URL prüfen und als PNG neu kodieren |
| `app/src/Members/ApplicationSignatures.php` | Antworten prüfen, speichern, lesen, anonymisieren |
| `app/src/Members/ApplicationPdf.php` | PDF eines Antrags |
| `app/src/Web/DeclarationController.php` | Einstellungsseiten |
| `app/templates/settings/declarations.twig`, `declaration_form.twig` | Liste und Formular |
| `app/templates/public/_declaration_step.twig` | eine Erklärung im Formular (auch Vorschau) |
| `app/templates/public/membership_application.twig` | Stepper-Formular |
| `public/assets/signature-pad.js` | Unterschriftsfeld |
| `public/assets/application-stepper.js` | Schritte, Prüfung je Schritt, Zusammenfassung |
| `app/src/Text/de/declarations.php` | Texte |
| `app/tests/Support/ApplicationInput.php` | gültige Erklärungs-Eingaben für Tests |

---

### Task 1: Migration und Standardsatz

**Files:**
- Create: `app/migrations/0022_application_declarations.sql`
- Test: `app/tests/Db/ApplicationDeclarationsMigrationTest.php`

**Interfaces:**
- Produces: Tabellen `application_declarations(id, position, active, current_version, created_at)`, `application_declaration_versions(declaration_id, version, title, content, kind, signature_required, actor_id, created_at)`, `application_signatures(id, application_id, declaration_id, version, granted, snapshot, snapshot_hash, signature_png, signature_sha256, signed_place, signed_at)`; `member_consents.kind` erlaubt `declaration`, neue Spalte `member_consents.declaration_id`.

- [ ] **Step 1: Failing test schreiben**

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Db;

use PDO;
use PDOException;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Tests\TestCase;

final class ApplicationDeclarationsMigrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
    }

    public function test_default_set_follows_paper_form(): void
    {
        $rows = $this->pdo->query('SELECT d.position, d.active, v.title, v.kind, v.signature_required
            FROM application_declarations d JOIN application_declaration_versions v
              ON v.declaration_id = d.id AND v.version = d.current_version ORDER BY d.position')->fetchAll();
        $this->assertSame([
            ['Antrag auf Mitgliedschaft', 'confirmation', true],
            ['Einwilligung zur vereinsinternen Kommunikation', 'consent', false],
            ['Datenschutzhinweise gemäß Art. 13 DSGVO', 'notice', true],
            ['Einwilligung in die Veröffentlichung von Personenbildnissen', 'consent', true],
        ], array_map(static fn(array $r): array => [$r['title'], $r['kind'], (bool) $r['signature_required']], $rows));
        $content = (string) $this->pdo->query("SELECT content FROM application_declaration_versions WHERE title LIKE 'Antrag%'")->fetchColumn();
        $this->assertStringContainsString('{verein}', $content);
    }

    public function test_versions_are_immutable(): void
    {
        $this->expectException(PDOException::class);
        $this->pdo->exec("UPDATE application_declaration_versions SET title = 'X'");
    }

    public function test_consents_accept_declaration_kind_only_with_declaration(): void
    {
        $id = (int) $this->pdo->query('SELECT id FROM application_declarations ORDER BY position LIMIT 1')->fetchColumn();
        $app = (int) $this->pdo->query("INSERT INTO membership_applications (applicant_name, email) VALUES ('A', 'a@example.org') RETURNING id")->fetchColumn();
        $this->pdo->prepare("INSERT INTO member_consents (application_id, kind, granted, document_version, snapshot_hash, source, declaration_id)
            VALUES (?, 'declaration', true, '1', ?, 'application', ?)")->execute([$app, str_repeat('a', 64), $id]);
        $this->expectException(PDOException::class);
        $this->pdo->prepare("INSERT INTO member_consents (application_id, kind, granted, document_version, snapshot_hash, source)
            VALUES (?, 'declaration', true, '1', ?, 'application')")->execute([$app, str_repeat('a', 64)]);
    }

    public function test_signatures_can_only_be_redacted_after_anonymization(): void
    {
        $id = (int) $this->pdo->query('SELECT id FROM application_declarations ORDER BY position LIMIT 1')->fetchColumn();
        $app = (int) $this->pdo->query("INSERT INTO membership_applications (applicant_name, email) VALUES ('A', 'a@example.org') RETURNING id")->fetchColumn();
        $this->pdo->prepare("INSERT INTO application_signatures (application_id, declaration_id, version, granted, snapshot, snapshot_hash, signed_place)
            VALUES (?, ?, 1, true, '{\"title\":\"T\"}', ?, 'Erlangen')")->execute([$app, $id, str_repeat('b', 64)]);
        $redact = "UPDATE application_signatures SET snapshot = '{}', snapshot_hash = '" . hash('sha256', '{}') . "', signed_place = '' WHERE application_id = $app";
        try {
            $this->pdo->exec($redact);
            $this->fail('Redaction before anonymization must fail');
        } catch (PDOException) {
        }
        $this->pdo->exec("UPDATE membership_applications SET anonymized_at = now() WHERE id = $app");
        $this->assertSame(1, $this->pdo->exec($redact));
    }
}
```

- [ ] **Step 2: Test laufen lassen – muss fehlschlagen**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php sh -c "cd app && vendor/bin/phpunit tests/Db/ApplicationDeclarationsMigrationTest.php"`
Expected: FAIL, `relation "application_declarations" does not exist`.

- [ ] **Step 3: Migration schreiben**

`app/migrations/0022_application_declarations.sql`:

```sql
-- Frei festlegbare Erklaerungen im Mitgliedsantrag mit digitaler Unterschrift.
CREATE TABLE application_declarations (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    position integer NOT NULL,
    active boolean NOT NULL DEFAULT true,
    current_version integer NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE application_declaration_versions (
    declaration_id bigint NOT NULL REFERENCES application_declarations (id),
    version integer NOT NULL CHECK (version > 0),
    title text NOT NULL CHECK (length(btrim(title)) BETWEEN 1 AND 200),
    content text NOT NULL CHECK (length(content) <= 20000),
    kind text NOT NULL CHECK (kind IN ('confirmation', 'consent', 'notice')),
    signature_required boolean NOT NULL,
    actor_id bigint REFERENCES users (id), -- NULL beim Standardsatz der Migration
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (declaration_id, version)
);
ALTER TABLE application_declarations ADD FOREIGN KEY (id, current_version)
    REFERENCES application_declaration_versions (declaration_id, version) DEFERRABLE INITIALLY DEFERRED;
CREATE TRIGGER application_declaration_versions_immutable BEFORE UPDATE OR DELETE ON application_declaration_versions
    FOR EACH ROW EXECUTE FUNCTION vk_reject_modification();
CREATE TRIGGER application_declaration_versions_no_truncate BEFORE TRUNCATE ON application_declaration_versions
    FOR EACH STATEMENT EXECUTE FUNCTION vk_reject_modification();

CREATE TABLE application_signatures (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    application_id bigint NOT NULL REFERENCES membership_applications (id),
    declaration_id bigint NOT NULL,
    version integer NOT NULL,
    granted boolean, -- NULL bei Hinweisen
    snapshot jsonb NOT NULL CHECK (jsonb_typeof(snapshot) = 'object'),
    snapshot_hash char(64) NOT NULL,
    signature_png bytea,
    signature_sha256 char(64),
    signed_place text NOT NULL DEFAULT '' CHECK (length(signed_place) <= 100),
    signed_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (declaration_id, version) REFERENCES application_declaration_versions (declaration_id, version),
    CHECK ((signature_png IS NULL) = (signature_sha256 IS NULL)),
    UNIQUE (application_id, declaration_id)
);
CREATE FUNCTION vk_signature_changes() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    -- Einzige erlaubte Aenderung: Schwaerzen nach Anonymisierung des Antrags.
    IF TG_OP = 'UPDATE' AND NEW.signature_png IS NULL AND NEW.signature_sha256 IS NULL
       AND NEW.signed_place = '' AND NEW.snapshot = '{}'::jsonb
       AND (to_jsonb(NEW) - ARRAY['signature_png', 'signature_sha256', 'signed_place', 'snapshot', 'snapshot_hash'])
         = (to_jsonb(OLD) - ARRAY['signature_png', 'signature_sha256', 'signed_place', 'snapshot', 'snapshot_hash'])
       AND EXISTS (SELECT 1 FROM membership_applications WHERE id = OLD.application_id AND anonymized_at IS NOT NULL)
    THEN RETURN NEW; END IF;
    RAISE EXCEPTION 'Application signatures are immutable';
END;
$$;
CREATE TRIGGER application_signatures_immutable BEFORE UPDATE OR DELETE ON application_signatures
    FOR EACH ROW EXECUTE FUNCTION vk_signature_changes();
CREATE TRIGGER application_signatures_no_truncate BEFORE TRUNCATE ON application_signatures
    FOR EACH STATEMENT EXECUTE FUNCTION vk_reject_modification();

ALTER TABLE member_consents DROP CONSTRAINT member_consents_kind_check;
ALTER TABLE member_consents ADD CONSTRAINT member_consents_kind_check
    CHECK (kind IN ('statute', 'privacy', 'media', 'newsletter', 'sepa', 'declaration'));
ALTER TABLE member_consents ADD COLUMN declaration_id bigint REFERENCES application_declarations (id);
ALTER TABLE member_consents ADD CONSTRAINT member_consents_declaration_check
    CHECK ((kind = 'declaration') = (declaration_id IS NOT NULL));

-- Standardsatz nach dem Papierformular; vereinsspezifische Angaben als Luecken.
WITH d AS (INSERT INTO application_declarations (position, current_version) VALUES (1, 1) RETURNING id)
INSERT INTO application_declaration_versions (declaration_id, version, title, content, kind, signature_required)
SELECT id, 1, 'Antrag auf Mitgliedschaft',
    '<p>Hiermit beantrage ich die Aufnahme in den {verein}.</p><p>Mit meiner Unterschrift erkenne ich die Satzung sowie die Geschäftsordnungen des Vereins in ihrer jeweils gültigen Fassung als verbindlich an. Die aktuelle Satzung habe ich zur Kenntnis genommen.</p>',
    'confirmation', true FROM d;

WITH d AS (INSERT INTO application_declarations (position, current_version) VALUES (2, 1) RETURNING id)
INSERT INTO application_declaration_versions (declaration_id, version, title, content, kind, signature_required)
SELECT id, 1, 'Einwilligung zur vereinsinternen Kommunikation',
    '<p>Ich bin damit einverstanden, dass meine Telefonnummer und E-Mail-Adresse vom Vorstand zur Organisation der Vereinsarbeit genutzt und – soweit erforderlich – an andere Vereinsmitglieder weitergegeben werden dürfen (z. B. zur Teilnahme an Kommunikationsgruppen).</p><p>Diese Einwilligung ist freiwillig und kann jederzeit mit Wirkung für die Zukunft ganz oder teilweise widerrufen werden.</p>',
    'consent', false FROM d;

WITH d AS (INSERT INTO application_declarations (position, current_version) VALUES (3, 1) RETURNING id)
INSERT INTO application_declaration_versions (declaration_id, version, title, content, kind, signature_required)
SELECT id, 1, 'Datenschutzhinweise gemäß Art. 13 DSGVO',
    '<h3>1. Verantwortlicher</h3><p>{verein}<br>[Vertretung, Anschrift und E-Mail-Adresse des Vereins eintragen]</p>'
 || '<h3>2. Zweck der Datenverarbeitung</h3><p>Die Verarbeitung personenbezogener Daten erfolgt ausschließlich zur Begründung und Durchführung des Mitgliedschaftsverhältnisses, zur Mitgliederverwaltung, zur Organisation und Durchführung von Vereinstätigkeiten sowie zur Erfüllung gesetzlicher Pflichten.</p>'
 || '<h3>3. Art der verarbeiteten Daten</h3><ul><li>Name, Vorname</li><li>Anschrift</li><li>Geburtsdatum</li><li>Telefonnummer</li><li>E-Mail-Adresse</li></ul><p>Die Bereitstellung dieser Daten ist für den Abschluss der Mitgliedschaft erforderlich.</p>'
 || '<h3>4. Rechtsgrundlagen der Verarbeitung</h3><ul><li>Art. 6 Abs. 1 lit. b DSGVO (Erfüllung des Mitgliedschaftsvertrags)</li><li>Art. 6 Abs. 1 lit. c DSGVO (gesetzliche Verpflichtungen)</li><li>Art. 6 Abs. 1 lit. a DSGVO (Einwilligung, z. B. Weitergabe von Kontaktdaten oder Bildveröffentlichungen)</li></ul>'
 || '<h3>5. Empfänger der Daten</h3><p>Personenbezogene Daten erhalten Vorstandsmitglieder sowie Funktions- und Amtsträger des Vereins, soweit dies zur Aufgabenwahrnehmung erforderlich ist. Eine Weitergabe an öffentliche Stellen erfolgt nur bei gesetzlicher Verpflichtung. Eine Übermittlung in Drittstaaten außerhalb der EU bzw. des EWR findet nicht statt.</p>'
 || '<h3>6. Speicherdauer</h3><p>Die personenbezogenen Daten werden für die Dauer der Mitgliedschaft gespeichert. Nach Beendigung der Mitgliedschaft werden die Daten grundsätzlich innerhalb eines Monats gelöscht, sofern keine gesetzlichen Aufbewahrungspflichten bestehen. Daten mit gesetzlichen Aufbewahrungspflichten werden nach Ablauf der Fristen gelöscht.</p>'
 || '<h3>7. Rechte der betroffenen Person</h3><ul><li>Recht auf Auskunft (Art. 15 DSGVO)</li><li>Recht auf Berichtigung (Art. 16 DSGVO)</li><li>Recht auf Löschung (Art. 17 DSGVO)</li><li>Recht auf Einschränkung der Verarbeitung (Art. 18 DSGVO)</li><li>Recht auf Datenübertragbarkeit (Art. 20 DSGVO)</li><li>Recht auf Widerspruch (Art. 21 DSGVO)</li><li>Recht auf Beschwerde bei einer Datenschutzaufsichtsbehörde (Art. 77 DSGVO)</li></ul><p>Zudem haben Sie das Recht, eine erteilte Einwilligung jederzeit mit Wirkung für die Zukunft zu widerrufen. Die Rechtmäßigkeit der bis zum Widerruf erfolgten Verarbeitung bleibt unberührt.</p>',
    'notice', true FROM d;

WITH d AS (INSERT INTO application_declarations (position, current_version) VALUES (4, 1) RETURNING id)
INSERT INTO application_declaration_versions (declaration_id, version, title, content, kind, signature_required)
SELECT id, 1, 'Einwilligung in die Veröffentlichung von Personenbildnissen',
    '<p>Freiwillig, nicht Voraussetzung für die Mitgliedschaft.</p><p>Ich willige ein, dass im Rahmen von Veranstaltungen des Vereins Foto- und Videoaufnahmen meiner Person angefertigt und insbesondere auf der Website sowie auf Social-Media-Kanälen des Vereins veröffentlicht werden dürfen.</p><p>Mir ist bewusst, dass veröffentlichte Aufnahmen weltweit abrufbar sein können, eine Weiterverwendung oder Veränderung durch Dritte nicht ausgeschlossen werden kann und eine vollständige Löschung im Internet nicht in jedem Fall gewährleistet werden kann.</p><p>Die Einwilligung gilt zeitlich unbeschränkt und kann jederzeit mit Wirkung für die Zukunft schriftlich gegenüber dem Vorstand widerrufen werden.</p>',
    'consent', true FROM d;
```

- [ ] **Step 4: Test laufen lassen – muss bestehen**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php sh -c "cd app && vendor/bin/phpunit tests/Db/ApplicationDeclarationsMigrationTest.php"`
Expected: `OK (4 tests, …)`

- [ ] **Step 5: Commit**

```bash
git add app/migrations/0022_application_declarations.sql app/tests/Db/ApplicationDeclarationsMigrationTest.php
git commit -m "Migration: versionierte Antragserklaerungen und Unterschriften

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: SignatureImage

**Files:**
- Create: `app/src/Members/SignatureImage.php`
- Create: `app/tests/Support/ApplicationInput.php` (nur `signature()` in diesem Task)
- Test: `app/tests/Members/SignatureImageTest.php`

**Interfaces:**
- Produces: `SignatureImage::fromDataUrl(string $value): string` – gibt neu kodierte PNG-Bytes zurück, wirft `Vereinskiste\Ledger\ValidationError(['signature' => 'declarations.error.signature_invalid'])`. `ApplicationInput::signature(int $width = 600, int $height = 160, bool $ink = true): string` liefert eine Data-URL.

- [ ] **Step 1: Test-Helfer und failing test schreiben**

`app/tests/Support/ApplicationInput.php`:

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Support;

final class ApplicationInput
{
    /** PNG-Data-URL wie vom Unterschriftsfeld: transparenter Grund, dunkler Strich. */
    public static function signature(int $width = 600, int $height = 160, bool $ink = true): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        if ($ink) {
            imagesetthickness($image, 4);
            $pen = imagecolorallocate($image, 17, 24, 39);
            imageline($image, 20, (int) ($height * 0.7), $width - 20, (int) ($height * 0.3), $pen);
            imageline($image, 40, (int) ($height * 0.3), (int) ($width / 2), (int) ($height * 0.8), $pen);
        }
        ob_start();
        imagepng($image);
        return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    }
}
```

`app/tests/Members/SignatureImageTest.php`:

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Members;

use PHPUnit\Framework\TestCase;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\SignatureImage;
use Vereinskiste\Tests\Support\ApplicationInput;

final class SignatureImageTest extends TestCase
{
    public function test_valid_signature_is_reencoded_png(): void
    {
        $png = SignatureImage::fromDataUrl(ApplicationInput::signature());
        $this->assertSame("\x89PNG", substr($png, 0, 4));
        $this->assertSame([600, 160], array_slice(getimagesizefromstring($png) ?: [], 0, 2));
    }

    /** @return array<string,array{string}> */
    public static function invalid(): array
    {
        return [
            'kein data-url' => ['https://example.org/a.png'],
            'falscher typ' => ['data:image/jpeg;base64,' . base64_encode('x')],
            'kein base64' => ['data:image/png;base64,***'],
            'kein bild' => ['data:image/png;base64,' . base64_encode('kein png')],
            'leer' => [ApplicationInput::signature(600, 160, false)],
            'zu breit' => [ApplicationInput::signature(1300, 160)],
            'zu hoch' => [ApplicationInput::signature(600, 500)],
            'zu gross' => ['data:image/png;base64,' . base64_encode(str_repeat('a', 200001))],
        ];
    }

    /** @dataProvider invalid */
    public function test_invalid_signatures_are_rejected(string $value): void
    {
        try {
            SignatureImage::fromDataUrl($value);
            $this->fail('expected ValidationError');
        } catch (ValidationError $e) {
            $this->assertSame(['signature' => 'declarations.error.signature_invalid'], $e->errors);
        }
    }
}
```

- [ ] **Step 2: Test laufen lassen – muss fehlschlagen**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php sh -c "cd app && vendor/bin/phpunit tests/Members/SignatureImageTest.php"`
Expected: FAIL, `Class "Vereinskiste\Members\SignatureImage" not found`.

- [ ] **Step 3: Implementieren**

`app/src/Members/SignatureImage.php`:

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Members;

use GdImage;
use Vereinskiste\Ledger\ValidationError;

/** Prueft eine gezeichnete Unterschrift (PNG-Data-URL) und kodiert sie ohne Metadaten neu. */
final class SignatureImage
{
    public const MAX_BYTES = 200000;
    public const MAX_WIDTH = 1200;
    public const MAX_HEIGHT = 400;
    private const PREFIX = 'data:image/png;base64,';
    private const MIN_INK_PIXELS = 30;

    public static function fromDataUrl(string $value): string
    {
        // Base64 ist 4/3 so lang wie die Rohdaten; laengere Eingaben gar nicht erst dekodieren.
        if (!str_starts_with($value, self::PREFIX) || strlen($value) > strlen(self::PREFIX) + intdiv(self::MAX_BYTES + 2, 3) * 4) {
            throw self::invalid();
        }
        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || $raw === '' || strlen($raw) > self::MAX_BYTES) {
            throw self::invalid();
        }
        $info = @getimagesizefromstring($raw);
        if ($info === false || $info[2] !== IMAGETYPE_PNG || $info[0] > self::MAX_WIDTH || $info[1] > self::MAX_HEIGHT) {
            throw self::invalid();
        }
        $image = @imagecreatefromstring($raw);
        if (!$image instanceof GdImage || !self::hasInk($image)) {
            throw self::invalid();
        }
        imagesavealpha($image, true);
        ob_start();
        imagepng($image, null, 9);
        return (string) ob_get_clean();
    }

    /** Mindestens einige deckende, dunkle Pixel; jede zweite Zeile/Spalte genuegt. */
    private static function hasInk(GdImage $image): bool
    {
        $count = 0;
        for ($y = 0, $h = imagesy($image); $y < $h; $y += 2) {
            for ($x = 0, $w = imagesx($image); $x < $w; $x += 2) {
                $c = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                if ($c['alpha'] < 64 && ($c['red'] + $c['green'] + $c['blue']) < 480 && ++$count >= self::MIN_INK_PIXELS) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function invalid(): ValidationError
    {
        return new ValidationError(['signature' => 'declarations.error.signature_invalid']);
    }
}
```

- [ ] **Step 4: Test laufen lassen – muss bestehen**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php sh -c "cd app && vendor/bin/phpunit tests/Members/SignatureImageTest.php"`
Expected: `OK (9 tests, …)`

- [ ] **Step 5: Commit**

```bash
git add app/src/Members/SignatureImage.php app/tests/Members/SignatureImageTest.php app/tests/Support/ApplicationInput.php
git commit -m "Unterschriftsbilder pruefen und neu kodieren

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Declarations-Repository und Texte

**Files:**
- Create: `app/src/Members/Declarations.php`
- Create: `app/src/Text/de/declarations.php`
- Modify: `app/src/Text/de.php` (Datei einbinden)
- Test: `app/tests/Members/DeclarationsTest.php`

**Interfaces:**
- Consumes: Tabellen aus Task 1.
- Produces (Klasse `Vereinskiste\Members\Declarations`):
  - `const KINDS = ['confirmation', 'consent', 'notice']`
  - `all(bool $onlyActive = false): list<array{id:int,position:int,active:bool,version:int,title:string,content:string,kind:string,signature_required:bool}>`
  - `active(): list<…>` (= `all(true)`)
  - `find(int $id): ?array` (gleiche Form)
  - `save(?int $id, array $input, int $actor): int` – Eingabe `title`, `content`, `kind`, `signature_required` (`'1'`); wirft `ValidationError` mit Schlüsseln `title`, `content`, `kind`
  - `setActive(int $id, bool $active, int $actor): void`
  - `move(int $id, string $direction, int $actor): void` (`'up'|'down'`)
  - `fingerprint(): string`
  - `static render(string $text, string $club, bool $html): string`

- [ ] **Step 1: Failing test schreiben**

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Members;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\Declarations;
use Vereinskiste\Tests\TestCase;

final class DeclarationsTest extends TestCase
{
    private PDO $pdo;
    private Declarations $declarations;
    private int $actor;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->actor = (int) $this->pdo->query("INSERT INTO users (email, name, password_hash) VALUES ('v@example.org', 'V', 'x') RETURNING id")->fetchColumn();
        $this->declarations = new Declarations($this->pdo);
    }

    public function test_create_appends_and_change_creates_new_version(): void
    {
        $id = $this->declarations->save(null, ['title' => 'Beitrag', 'content' => '<p>Ich zahle <script>x</script>den Beitrag.</p>', 'kind' => 'confirmation', 'signature_required' => '1'], $this->actor);
        $created = $this->declarations->find($id);
        $this->assertSame(5, $created['position']);
        $this->assertSame(1, $created['version']);
        $this->assertSame('<p>Ich zahle den Beitrag.</p>', $created['content']);
        $this->assertTrue($created['signature_required']);

        $this->declarations->save($id, ['title' => 'Beitrag', 'content' => '<p>Ich zahle den Beitrag.</p>', 'kind' => 'confirmation', 'signature_required' => '1'], $this->actor);
        $this->assertSame(1, $this->declarations->find($id)['version'], 'unchanged input keeps version');

        $this->declarations->save($id, ['title' => 'Beitrag', 'content' => '<p>Ich zahle den Beitrag.</p>', 'kind' => 'consent'], $this->actor);
        $changed = $this->declarations->find($id);
        $this->assertSame(2, $changed['version']);
        $this->assertSame('consent', $changed['kind']);
        $this->assertFalse($changed['signature_required']);
        $this->assertSame(2, (int) $this->pdo->query("SELECT count(*) FROM application_declaration_versions WHERE declaration_id = $id")->fetchColumn());
        $this->assertSame(2, (int) $this->pdo->query("SELECT count(*) FROM audit_log WHERE action LIKE 'application_declaration_%'")->fetchColumn());
    }

    public function test_invalid_input_is_rejected(): void
    {
        try {
            $this->declarations->save(null, ['title' => '', 'content' => str_repeat('a', 20001), 'kind' => 'other'], $this->actor);
            $this->fail('expected ValidationError');
        } catch (ValidationError $e) {
            $this->assertSame(['title', 'content', 'kind'], array_keys($e->errors));
        }
    }

    public function test_move_and_deactivate_change_active_order_and_fingerprint(): void
    {
        $before = $this->declarations->fingerprint();
        $ids = array_column($this->declarations->active(), 'id');
        $this->declarations->move($ids[1], 'up', $this->actor);
        $this->assertSame([$ids[1], $ids[0], $ids[2], $ids[3]], array_column($this->declarations->active(), 'id'));
        $this->declarations->move($ids[1], 'up', $this->actor); // erste Position bleibt
        $this->assertSame($ids[1], $this->declarations->active()[0]['id']);
        $this->declarations->setActive($ids[3], false, $this->actor);
        $this->assertCount(3, $this->declarations->active());
        $this->assertCount(4, $this->declarations->all());
        $this->assertNotSame($before, $this->declarations->fingerprint());
    }

    public function test_render_replaces_club_placeholder_safely(): void
    {
        $this->assertSame('<p>Aufnahme in den A &amp; B e.V.</p>', Declarations::render('<p>Aufnahme in den {verein}</p>', 'A & B e.V.', true));
        $this->assertSame('Antrag A & B e.V.', Declarations::render('Antrag {verein}', 'A & B e.V.', false));
    }
}
```

- [ ] **Step 2: Test laufen lassen – muss fehlschlagen**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php sh -c "cd app && vendor/bin/phpunit tests/Members/DeclarationsTest.php"`
Expected: FAIL, `Class "Vereinskiste\Members\Declarations" not found`.

- [ ] **Step 3: Repository implementieren**

`app/src/Members/Declarations.php`:

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Members;

use PDO;
use Vereinskiste\Audit\AuditLog;
use Vereinskiste\ClubDocuments\Content;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

/** Vom Vorstand festgelegte Erklaerungen des Mitgliedsantrags; jede Aenderung ist eine neue Version. */
final class Declarations
{
    public const KINDS = ['confirmation', 'consent', 'notice'];
    public const MAX_CONTENT = 20000;
    private const SELECT = 'SELECT d.id, d.position, d.active, v.version, v.title, v.content, v.kind, v.signature_required
        FROM application_declarations d
        JOIN application_declaration_versions v ON v.declaration_id = d.id AND v.version = d.current_version';

    public function __construct(private PDO $pdo) {}

    /** @return list<array<string,mixed>> */
    public function all(bool $onlyActive = false): array
    {
        $rows = $this->pdo->query(self::SELECT . ($onlyActive ? ' WHERE d.active' : '') . ' ORDER BY d.position, d.id')->fetchAll();
        return array_map(self::hydrate(...), $rows);
    }

    /** @return list<array<string,mixed>> */
    public function active(): array
    {
        return $this->all(true);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE d.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::hydrate($row);
    }

    /** @param array<string,mixed> $input */
    public function save(?int $id, array $input, int $actor): int
    {
        $errors = [];
        $title = '';
        try {
            $title = Fields::read($input, ['title' => 200], ['title'])['title'];
        } catch (ValidationError $e) {
            $errors += $e->errors;
        }
        $content = Content::clean(is_string($input['content'] ?? null) ? $input['content'] : '');
        if (mb_strlen($content) > self::MAX_CONTENT) {
            $errors['content'] = 'declarations.error.content_length';
        }
        $kind = $input['kind'] ?? '';
        if (!in_array($kind, self::KINDS, true)) {
            $errors['kind'] = 'foundation.invalid';
        }
        if ($errors !== []) {
            throw new ValidationError($errors);
        }
        $signature = ($input['signature_required'] ?? '') === '1';

        return Transaction::run($this->pdo, function () use ($id, $title, $content, $kind, $signature, $actor): int {
            $audit = new AuditLog($this->pdo);
            if ($id === null) {
                $position = (int) $this->pdo->query('SELECT COALESCE(MAX(position), 0) + 1 FROM application_declarations')->fetchColumn();
                $stmt = $this->pdo->prepare('INSERT INTO application_declarations (position, current_version) VALUES (?, 1) RETURNING id');
                $stmt->execute([$position]);
                $id = (int) $stmt->fetchColumn();
                $this->insertVersion($id, 1, $title, $content, $kind, $signature, $actor);
                $audit->record('application_declaration_created', $actor, ['declaration_id' => $id, 'title' => $title]);
                return $id;
            }
            $this->pdo->prepare('SELECT id FROM application_declarations WHERE id = ? FOR UPDATE')->execute([$id]);
            $current = $this->find($id);
            if ($current === null) {
                throw new ValidationError(['declaration' => 'foundation.invalid']);
            }
            if ([$current['title'], $current['content'], $current['kind'], $current['signature_required']] === [$title, $content, $kind, $signature]) {
                return $id;
            }
            $version = $current['version'] + 1;
            $this->insertVersion($id, $version, $title, $content, $kind, $signature, $actor);
            $this->pdo->prepare('UPDATE application_declarations SET current_version = ? WHERE id = ?')->execute([$version, $id]);
            $audit->record('application_declaration_changed', $actor, ['declaration_id' => $id, 'version' => $version]);
            return $id;
        });
    }

    public function setActive(int $id, bool $active, int $actor): void
    {
        $stmt = $this->pdo->prepare('UPDATE application_declarations SET active = ? WHERE id = ?');
        $stmt->execute([$active ? 'true' : 'false', $id]);
        if ($stmt->rowCount() === 0) {
            throw new ValidationError(['declaration' => 'foundation.invalid']);
        }
        (new AuditLog($this->pdo))->record('application_declaration_toggled', $actor, ['declaration_id' => $id, 'active' => $active]);
    }

    public function move(int $id, string $direction, int $actor): void
    {
        Transaction::run($this->pdo, function () use ($id, $direction, $actor): void {
            $order = $this->pdo->query('SELECT id FROM application_declarations ORDER BY position, id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
            $order = array_map('intval', $order);
            $index = array_search($id, $order, true);
            $target = $direction === 'up' ? $index - 1 : $index + 1;
            if ($index === false || !in_array($direction, ['up', 'down'], true) || !isset($order[$target])) {
                return;
            }
            [$order[$index], $order[$target]] = [$order[$target], $order[$index]];
            $update = $this->pdo->prepare('UPDATE application_declarations SET position = ? WHERE id = ?');
            foreach ($order as $position => $declaration) {
                $update->execute([$position + 1, $declaration]);
            }
            (new AuditLog($this->pdo))->record('application_declaration_moved', $actor, ['declaration_id' => $id, 'direction' => $direction]);
        });
    }

    /** Aendert sich, sobald eine aktive Erklaerung neu, geaendert, verschoben oder deaktiviert wird. */
    public function fingerprint(): string
    {
        $parts = array_map(static fn(array $d): array => [$d['id'], $d['version']], $this->active());
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    public static function render(string $text, string $club, bool $html): string
    {
        return str_replace('{verein}', $html ? htmlspecialchars($club, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $club, $text);
    }

    private function insertVersion(int $id, int $version, string $title, string $content, string $kind, bool $signature, int $actor): void
    {
        $this->pdo->prepare('INSERT INTO application_declaration_versions (declaration_id, version, title, content, kind, signature_required, actor_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$id, $version, $title, $content, $kind, $signature ? 'true' : 'false', $actor]);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrate(array $row): array
    {
        return ['id' => (int) $row['id'], 'position' => (int) $row['position'], 'active' => (bool) $row['active'],
            'version' => (int) $row['version'], 'title' => (string) $row['title'], 'content' => (string) $row['content'],
            'kind' => (string) $row['kind'], 'signature_required' => (bool) $row['signature_required']];
    }
}
```

- [ ] **Step 4: Texte anlegen und einbinden**

`app/src/Text/de/declarations.php`:

```php
<?php
declare(strict_types=1);

return [
    'declarations.title' => 'Antragserklärungen',
    'declarations.help' => 'Diese Erklärungen erscheinen im Online-Mitgliedsantrag als eigene Schritte. Jede Änderung wird als neue Version gespeichert; Anträge behalten den Text, den die Person unterschrieben hat. {verein} wird durch den Vereinsnamen ersetzt.',
    'declarations.new' => 'Neue Erklärung',
    'declarations.edit' => 'Erklärung bearbeiten',
    'declarations.preview' => 'Vorschau im Antrag',
    'declarations.field.title' => 'Titel',
    'declarations.field.content' => 'Text',
    'declarations.field.kind' => 'Art',
    'declarations.field.signature_required' => 'Unterschrift erforderlich',
    'declarations.field.version' => 'Version',
    'declarations.field.position' => 'Reihenfolge',
    'declarations.kind.confirmation' => 'Pflichtbestätigung (Checkbox)',
    'declarations.kind.consent' => 'Freiwillige Einwilligung (Ja/Nein)',
    'declarations.kind.notice' => 'Hinweis (nur Text)',
    'declarations.active' => 'Aktiv',
    'declarations.inactive' => 'Inaktiv',
    'declarations.activate' => 'Aktivieren',
    'declarations.deactivate' => 'Deaktivieren',
    'declarations.move_up' => 'Nach oben',
    'declarations.move_down' => 'Nach unten',
    'declarations.none' => 'Noch keine Erklärungen angelegt.',
    'declarations.confirm_label' => 'Ich bestätige diese Erklärung.',
    'declarations.consent_yes' => 'Ich willige ein',
    'declarations.consent_no' => 'Ich willige nicht ein',
    'declarations.noticed' => 'Zur Kenntnis genommen',
    'declarations.confirmed' => 'Bestätigt',
    'declarations.signature' => 'Unterschrift',
    'declarations.signature_hint' => 'Hier unterschreiben',
    'declarations.signature_clear' => 'Löschen',
    'declarations.signature_enlarge' => 'Größer unterschreiben',
    'declarations.signature_apply' => 'Übernehmen',
    'declarations.signature_missing' => 'Keine Unterschrift',
    'declarations.place' => 'Ort',
    'declarations.date' => 'Datum',
    'declarations.place_date' => 'Ort, Datum',
    'declarations.error.content_length' => 'Der Text ist zu lang (höchstens 20.000 Zeichen).',
    'declarations.error.confirm' => 'Bitte bestätige diese Erklärung.',
    'declarations.error.choose' => 'Bitte wähle „Ich willige ein“ oder „Ich willige nicht ein“.',
    'declarations.error.signature_required' => 'Bitte unterschreibe hier.',
    'declarations.error.signature_invalid' => 'Die Unterschrift konnte nicht gelesen werden. Bitte erneut unterschreiben.',
    'declarations.error.none' => 'Der Mitgliedsantrag ist derzeit nicht verfügbar.',
    'audit.action.application_declaration_created' => 'Antragserklärung angelegt',
    'audit.action.application_declaration_changed' => 'Antragserklärung geändert',
    'audit.action.application_declaration_toggled' => 'Antragserklärung (de)aktiviert',
    'audit.action.application_declaration_moved' => 'Antragserklärung verschoben',
    'audit.action.application_pdf_downloaded' => 'Mitgliedsantrag als PDF heruntergeladen',
];
```

In `app/src/Text/de.php` am Ende der `array_merge`-Liste ergänzen: `, require __DIR__ . '/de/declarations.php'` (vor der schließenden Klammer).

- [ ] **Step 5: Tests laufen lassen – müssen bestehen**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php sh -c "cd app && vendor/bin/phpunit tests/Members/DeclarationsTest.php tests/Text"`
Expected: `OK`

- [ ] **Step 6: Commit**

```bash
git add app/src/Members/Declarations.php app/src/Text/de/declarations.php app/src/Text/de.php app/tests/Members/DeclarationsTest.php
git commit -m "Antragserklaerungen versioniert verwalten

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Erklärungen beim Absenden prüfen und speichern

**Files:**
- Create: `app/src/Members/ApplicationSignatures.php`
- Modify: `app/src/Members/ApplicationRepository.php` (`submit()`: Satzung/Datenschutz-Checkboxen ersetzen)
- Modify: `app/src/Privacy/Consents.php` (`fingerprint()`, `fromApplication()`, neue Methode `fromDeclaration()`)
- Modify: `app/tests/Support/ApplicationInput.php` (`declarations()`)
- Modify: alle Tests, die Anträge einreichen (siehe Step 5)
- Test: `app/tests/Members/ApplicationSignaturesTest.php`

**Interfaces:**
- Consumes: `Declarations::active()`, `Declarations::render()`, `SignatureImage::fromDataUrl()`.
- Produces (`Vereinskiste\Members\ApplicationSignatures`):
  - `read(array $input, string $club): array{place:string, rows:list<array{declaration_id:int,version:int,kind:string,granted:?bool,snapshot:array,png:?string}>}` – Eingabe `declaration[<id>]` (`'1'` bzw. `'yes'|'no'`), `signature[<id>]` (Data-URL), `signed_place`. Fehlerschlüssel `declaration_<id>`, `signature_<id>`, `signed_place`, `declarations`.
  - `store(int $applicationId, array $read): void`
  - `forApplication(int $applicationId): list<array{id:int,declaration_id:int,version:int,kind:string,granted:?bool,snapshot:array,snapshot_hash:string,has_signature:bool,signature_sha256:?string,signed_place:string,signed_at:string}>`
  - `png(int $applicationId, int $signatureId): ?string`
  - `redact(int $applicationId): void`
  - `Consents::fromDeclaration(int $application, int $declarationId, bool $granted, array $snapshot): void`
  - `ApplicationInput::declarations(PDO $pdo, string $consent = 'no'): array`

- [ ] **Step 1: Test-Helfer erweitern**

In `app/tests/Support/ApplicationInput.php` ergänzen (mit `use PDO; use Vereinskiste\Members\Declarations;` oben):

```php
    /** Gueltige Antworten fuer alle aktiven Erklaerungen. @return array<string,mixed> */
    public static function declarations(PDO $pdo, string $consent = 'no'): array
    {
        $input = ['declaration' => [], 'signature' => [], 'signed_place' => 'Erlangen'];
        foreach ((new Declarations($pdo))->active() as $d) {
            if ($d['kind'] === 'confirmation') {
                $input['declaration'][(string) $d['id']] = '1';
            } elseif ($d['kind'] === 'consent') {
                $input['declaration'][(string) $d['id']] = $consent;
            }
            if ($d['signature_required']) {
                $input['signature'][(string) $d['id']] = self::signature();
            }
        }
        return $input;
    }
```

- [ ] **Step 2: Failing test schreiben**

`app/tests/Members/ApplicationSignaturesTest.php`:

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Members;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\ApplicationRepository;
use Vereinskiste\Members\ApplicationSignatures;
use Vereinskiste\Members\Declarations;
use Vereinskiste\Tests\Support\ApplicationInput;
use Vereinskiste\Tests\TestCase;

final class ApplicationSignaturesTest extends TestCase
{
    private PDO $pdo;
    private ApplicationRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->pdo->exec("INSERT INTO users (email, name, password_hash) VALUES ('v@example.org', 'V', 'x')");
        $this->pdo->exec("INSERT INTO settings (key, value) VALUES ('club_name', 'FSI Jura e.V.') ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value");
        $this->repo = new ApplicationRepository($this->pdo);
    }

    /** @return array<string,mixed> */
    private function person(): array
    {
        return ['first_name' => 'Erika', 'last_name' => 'Muster', 'email' => 'erika@example.org'];
    }

    public function test_submit_stores_snapshots_signatures_and_consents(): void
    {
        $invite = $this->repo->createInvite(1, 'Test', 7);
        $id = $this->repo->submit($invite['token'], $this->person() + ApplicationInput::declarations($this->pdo, 'yes'));

        $rows = (new ApplicationSignatures($this->pdo))->forApplication($id);
        $this->assertCount(4, $rows);
        $this->assertSame('Antrag auf Mitgliedschaft', $rows[0]['snapshot']['title']);
        $this->assertStringContainsString('Aufnahme in den FSI Jura e.V.', $rows[0]['snapshot']['content']);
        $this->assertSame([true, true, null, true], array_column($rows, 'granted'));
        $this->assertSame([true, false, true, true], array_column($rows, 'has_signature'));
        $this->assertSame('Erlangen', $rows[0]['signed_place']);
        $this->assertSame(hash('sha256', json_encode($rows[0]['snapshot'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), $rows[0]['snapshot_hash']);
        $png = (new ApplicationSignatures($this->pdo))->png($id, $rows[0]['id']);
        $this->assertSame(hash('sha256', (string) $png), $rows[0]['signature_sha256']);

        $kinds = $this->pdo->query("SELECT kind, granted, declaration_id IS NOT NULL AS linked FROM member_consents WHERE application_id = $id ORDER BY id")->fetchAll();
        $this->assertSame(3, count($kinds), 'confirmation + 2 consents, notice not recorded');
        $this->assertSame(['declaration'], array_values(array_unique(array_column($kinds, 'kind'))));
    }

    public function test_missing_answers_signatures_and_place_are_reported_per_declaration(): void
    {
        $invite = $this->repo->createInvite(1, 'Test', 7);
        $ids = array_column((new Declarations($this->pdo))->active(), 'id');
        $input = $this->person() + ApplicationInput::declarations($this->pdo);
        unset($input['declaration'][(string) $ids[0]], $input['declaration'][(string) $ids[1]]);
        $input['signature'][(string) $ids[2]] = '';
        $input['signature'][(string) $ids[3]] = ApplicationInput::signature(600, 160, false);
        $input['signed_place'] = ' ';
        try {
            $this->repo->submit($invite['token'], $input);
            $this->fail('expected ValidationError');
        } catch (ValidationError $e) {
            $this->assertSame('declarations.error.confirm', $e->errors['declaration_' . $ids[0]]);
            $this->assertSame('declarations.error.choose', $e->errors['declaration_' . $ids[1]]);
            $this->assertSame('declarations.error.signature_required', $e->errors['signature_' . $ids[2]]);
            $this->assertSame('declarations.error.signature_invalid', $e->errors['signature_' . $ids[3]]);
            $this->assertSame('foundation.required', $e->errors['signed_place']);
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM membership_applications')->fetchColumn());
    }

    public function test_without_active_declarations_no_application_is_possible(): void
    {
        $this->pdo->exec('UPDATE application_declarations SET active = false');
        $invite = $this->repo->createInvite(1, 'Test', 7);
        try {
            $this->repo->submit($invite['token'], $this->person());
            $this->fail('expected ValidationError');
        } catch (ValidationError $e) {
            $this->assertSame('declarations.error.none', $e->errors['declarations']);
        }
    }

    public function test_place_is_optional_without_signatures(): void
    {
        $this->pdo->exec('UPDATE application_declarations SET active = false WHERE position <> 2');
        $invite = $this->repo->createInvite(1, 'Test', 7);
        $input = $this->person() + ApplicationInput::declarations($this->pdo);
        $input['signed_place'] = '';
        $this->assertGreaterThan(0, $this->repo->submit($invite['token'], $input));
    }

    public function test_changed_declaration_invalidates_form_fingerprint(): void
    {
        $consents = new \Vereinskiste\Privacy\Consents($this->pdo);
        $before = $consents->fingerprint();
        $id = (new Declarations($this->pdo))->active()[0]['id'];
        (new Declarations($this->pdo))->save($id, ['title' => 'Neu', 'content' => '<p>Neu</p>', 'kind' => 'confirmation', 'signature_required' => '1'], 1);
        $this->assertNotSame($before, $consents->fingerprint());
    }
}
```

- [ ] **Step 3: Test laufen lassen – muss fehlschlagen**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php sh -c "cd app && vendor/bin/phpunit tests/Members/ApplicationSignaturesTest.php"`
Expected: FAIL, `Class "Vereinskiste\Members\ApplicationSignatures" not found`.

- [ ] **Step 4: Implementieren**

`app/src/Members/ApplicationSignatures.php`:

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Members;

use PDO;
use Vereinskiste\Ledger\TextInput;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Privacy\Consents;

/** Die zu einem Antrag abgegebenen Erklaerungen mit Snapshot und Unterschrift. */
final class ApplicationSignatures
{
    public function __construct(private PDO $pdo) {}

    /** @param array<string,mixed> $input @return array{place:string,rows:list<array<string,mixed>>} */
    public function read(array $input, string $club): array
    {
        $declarations = (new Declarations($this->pdo))->active();
        $answers = is_array($input['declaration'] ?? null) ? $input['declaration'] : [];
        $signatures = is_array($input['signature'] ?? null) ? $input['signature'] : [];
        $place = is_string($input['signed_place'] ?? null) ? trim($input['signed_place']) : '';
        $errors = $rows = [];
        $needsPlace = false;
        if ($declarations === []) {
            $errors['declarations'] = 'declarations.error.none';
        }
        foreach ($declarations as $d) {
            $key = (string) $d['id'];
            $answer = $answers[$key] ?? '';
            $granted = null;
            if ($d['kind'] === 'confirmation') {
                $granted = $answer === '1' ? true : null;
                if ($granted === null) { $errors['declaration_' . $key] = 'declarations.error.confirm'; }
            } elseif ($d['kind'] === 'consent') {
                $granted = match ($answer) { 'yes' => true, 'no' => false, default => null };
                if ($granted === null) { $errors['declaration_' . $key] = 'declarations.error.choose'; }
            }
            $png = null;
            if ($d['signature_required']) {
                $needsPlace = true;
                $raw = $signatures[$key] ?? '';
                if (!is_string($raw) || $raw === '') {
                    $errors['signature_' . $key] = 'declarations.error.signature_required';
                } else {
                    try {
                        $png = SignatureImage::fromDataUrl($raw);
                    } catch (ValidationError) {
                        $errors['signature_' . $key] = 'declarations.error.signature_invalid';
                    }
                }
            }
            $rows[] = ['declaration_id' => $d['id'], 'version' => $d['version'], 'kind' => $d['kind'], 'granted' => $granted, 'png' => $png,
                'snapshot' => ['title' => Declarations::render($d['title'], $club, false), 'content' => Declarations::render($d['content'], $club, true),
                    'kind' => $d['kind'], 'version' => $d['version']]];
        }
        if (mb_strlen($place) > 100) {
            $errors['signed_place'] = 'foundation.invalid';
        } elseif ($needsPlace && TextInput::isBlank($place)) {
            $errors['signed_place'] = 'foundation.required';
        }
        if ($errors !== []) {
            throw new ValidationError($errors);
        }
        return ['place' => $place, 'rows' => $rows];
    }

    /** @param array{place:string,rows:list<array<string,mixed>>} $read */
    public function store(int $applicationId, array $read): void
    {
        $insert = $this->pdo->prepare('INSERT INTO application_signatures
            (application_id, declaration_id, version, granted, snapshot, snapshot_hash, signature_png, signature_sha256, signed_place)
            VALUES (?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?)');
        $consents = new Consents($this->pdo);
        foreach ($read['rows'] as $row) {
            $json = json_encode($row['snapshot'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $png = $row['png'];
            $insert->bindValue(1, $applicationId, PDO::PARAM_INT);
            $insert->bindValue(2, $row['declaration_id'], PDO::PARAM_INT);
            $insert->bindValue(3, $row['version'], PDO::PARAM_INT);
            $insert->bindValue(4, $row['granted'] === null ? null : ($row['granted'] ? 'true' : 'false'), $row['granted'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insert->bindValue(5, $json);
            $insert->bindValue(6, hash('sha256', $json));
            $insert->bindValue(7, $png, $png === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
            $insert->bindValue(8, $png === null ? null : hash('sha256', $png), $png === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insert->bindValue(9, $read['place']);
            $insert->execute();
            if ($row['granted'] !== null) {
                $consents->fromDeclaration($applicationId, $row['declaration_id'], $row['granted'], $row['snapshot']);
            }
        }
    }

    /** @return list<array<string,mixed>> */
    public function forApplication(int $applicationId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, declaration_id, version, granted, snapshot, snapshot_hash, signature_png IS NOT NULL AS has_signature,
            signature_sha256, signed_place, signed_at FROM application_signatures WHERE application_id = ?
            ORDER BY (SELECT position FROM application_declarations d WHERE d.id = declaration_id), id');
        $stmt->execute([$applicationId]);
        return array_map(static function (array $r): array {
            $snapshot = json_decode((string) $r['snapshot'], true, 512, JSON_THROW_ON_ERROR);
            return ['id' => (int) $r['id'], 'declaration_id' => (int) $r['declaration_id'], 'version' => (int) $r['version'],
                'kind' => (string) ($snapshot['kind'] ?? ''), 'granted' => $r['granted'] === null ? null : (bool) $r['granted'],
                'snapshot' => $snapshot, 'snapshot_hash' => (string) $r['snapshot_hash'], 'has_signature' => (bool) $r['has_signature'],
                'signature_sha256' => $r['signature_sha256'], 'signed_place' => (string) $r['signed_place'], 'signed_at' => (string) $r['signed_at']];
        }, $stmt->fetchAll());
    }

    public function png(int $applicationId, int $signatureId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT signature_png FROM application_signatures WHERE application_id = ? AND id = ? AND signature_png IS NOT NULL');
        $stmt->execute([$applicationId, $signatureId]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            return null;
        }
        return is_resource($value) ? (string) stream_get_contents($value) : (string) $value;
    }

    /** Nur innerhalb einer Anonymisierung (Trigger verlangt anonymized_at). */
    public function redact(int $applicationId): void
    {
        $this->pdo->prepare("UPDATE application_signatures SET signature_png = NULL, signature_sha256 = NULL, signed_place = '',
            snapshot = '{}'::jsonb, snapshot_hash = ? WHERE application_id = ?")->execute([hash('sha256', '{}'), $applicationId]);
    }
}
```

In `app/src/Privacy/Consents.php`:

```php
    public function fingerprint(): string
    {
        return hash('sha256',json_encode([(new \Vereinskiste\Members\Declarations($this->pdo))->fingerprint(),$this->snapshot('sepa')],JSON_THROW_ON_ERROR));
    }
    public function fromApplication(int $application,array $input): void
    {
        if(!empty($input['sepa_mandate_consent'])) { $this->append(null,$application,'sepa',true,$this->snapshot('sepa'),'application',null,''); }
    }
    /** Einwilligungen und Bestaetigungen aus Antragserklaerungen; Hinweise werden nicht erfasst. */
    public function fromDeclaration(int $application,int $declarationId,bool $granted,array $snapshot): void
    {
        $this->append(null,$application,'declaration',$granted,$snapshot,'application',null,'',$declarationId);
    }
```

und `append()` um den Parameter erweitern:

```php
    private function append(?int $member,?int $application,string $kind,bool $granted,array $snapshot,string $source,?int $actor,string $reason,?int $declaration=null):void
    {
        $json=json_encode($snapshot,JSON_THROW_ON_ERROR);
        $this->pdo->prepare('INSERT INTO member_consents(member_id,application_id,kind,granted,document_version,snapshot,snapshot_hash,source,recorded_by,reason,declaration_id) VALUES(?,?,?,?,?,?::jsonb,?,?,?,?,?)')
            ->execute([$member,$application,$kind,$granted?'true':'false',(string)($snapshot['version']??''),$json,hash('sha256',$json),$source,$actor,$reason,$declaration]);
    }
```

In `app/src/Members/ApplicationRepository.php` in `submit()`:
1. Die Blöcke `$consentStatute …` und `$consentPrivacy …` (inkl. Fehler) löschen.
2. Direkt vor `if ($errors !== [])` einfügen:

```php
            $declarations = ['place' => '', 'rows' => []];
            try {
                $club = (string) ((new \Vereinskiste\Club\Profile($this->pdo))->get()['data']['name'] ?? '');
                $declarations = (new ApplicationSignatures($this->pdo))->read($input, $club);
            } catch (ValidationError $e) {
                $errors += $e->errors;
            }
```

3. In `$data` die Einträge `'consent_statute'` und `'consent_privacy'` löschen.
4. Nach dem `UPDATE membership_application_invites …` und vor `$consents->fromApplication(...)` einfügen:

```php
            (new ApplicationSignatures($this->pdo))->store($appId, $declarations);
```

- [ ] **Step 5: Bestehende Tests auf Erklärungen umstellen**

In jeder dieser Dateien jedes Eingabe-Array, das `'consent_statute' => '1', 'consent_privacy' => '1'` enthält, durch dieselben Daten ohne diese zwei Schlüssel plus `+ ApplicationInput::declarations($this->pdo)` ersetzen und `use Vereinskiste\Tests\Support\ApplicationInput;` ergänzen:
`app/tests/Members/ApplicationRepositoryTest.php`, `app/tests/Mail/ApplicationOutboxTest.php`, `app/tests/Privacy/PrivacyTest.php`, `app/tests/Web/MembershipApplicationPagesTest.php`, `app/tests/Web/ModulesPagesTest.php`.

Beispiel (`MembershipApplicationPagesTest::test_reject_application_records_reason`):

```php
        $appId = $repo->submit($invite['token'], [
            'name' => 'Antragsteller',
            'email' => 'test@example.org',
        ] + ApplicationInput::declarations($this->pdo));
```

Tests, die gezielt das Fehlen von `consent_statute` prüfen (`grep -n "consent_statute" app/tests`), prüfen stattdessen `declaration_<id der ersten aktiven Erklärung>` mit `declarations.error.confirm`.

`postForm()` reicht verschachtelte Arrays unverändert als ParsedBody durch; der PHPDoc `@param array<string,string>` in `WebHarness::postForm` wird zu `@param array<string,mixed>`.

- [ ] **Step 6: Alle PHP-Tests laufen lassen**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php sh -c "cd app && vendor/bin/phpunit"`
Expected: `OK` (Laufzeit etwa 15 Minuten unter Docker Desktop).

- [ ] **Step 7: Commit**

```bash
git add app/src/Members app/src/Privacy/Consents.php app/tests
git commit -m "Antragserklaerungen beim Absenden pruefen und speichern

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Anonymisierung und Datenschutzauskunft

**Files:**
- Modify: `app/src/Privacy/ApplicationPrivacy.php` (`redact()`)
- Modify: `app/src/Privacy/MemberPrivacy.php` (`disclosure()`)
- Test: `app/tests/Privacy/ApplicationSignaturePrivacyTest.php`

**Interfaces:**
- Consumes: `ApplicationSignatures::redact()`, `ApplicationInput::declarations()`.
- Produces: Auskunft enthält Schlüssel `signatures` (Liste mit `snapshot` als Array und `signature_png` als Base64).

- [ ] **Step 1: Failing test schreiben**

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Privacy;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Members\ApplicationRepository;
use Vereinskiste\Privacy\ApplicationPrivacy;
use Vereinskiste\Privacy\MemberPrivacy;
use Vereinskiste\Tests\Support\ApplicationInput;
use Vereinskiste\Tests\TestCase;

final class ApplicationSignaturePrivacyTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->pdo->exec("INSERT INTO users (email, name, password_hash) VALUES ('v@example.org', 'V', 'x')");
    }

    private function application(): int
    {
        $repo = new ApplicationRepository($this->pdo);
        $invite = $repo->createInvite(1, 'Test', 7);
        return $repo->submit($invite['token'], ['first_name' => 'Erika', 'last_name' => 'Muster', 'email' => 'e@example.org'] + ApplicationInput::declarations($this->pdo));
    }

    public function test_redaction_removes_signatures_and_snapshots(): void
    {
        $id = $this->application();
        Transaction::run($this->pdo, fn() => (new ApplicationPrivacy($this->pdo))->redact($id, 'Anonymisierter Antrag #' . $id));
        $row = $this->pdo->query("SELECT count(*) FILTER (WHERE signature_png IS NOT NULL) AS png, count(*) FILTER (WHERE snapshot <> '{}'::jsonb) AS snap,
            count(*) FILTER (WHERE signed_place <> '') AS place FROM application_signatures WHERE application_id = $id")->fetch();
        $this->assertSame([0, 0, 0], [(int) $row['png'], (int) $row['snap'], (int) $row['place']]);
    }

    public function test_disclosure_contains_signatures_of_member(): void
    {
        $id = $this->application();
        $member = (new ApplicationRepository($this->pdo))->approve($id, 1);
        $data = (new MemberPrivacy($this->pdo))->disclosure($member);
        $this->assertCount(4, $data['signatures']);
        $this->assertSame('Antrag auf Mitgliedschaft', $data['signatures'][0]['snapshot']['title']);
        $this->assertNotSame('', base64_decode((string) $data['signatures'][0]['signature_png'], true));
    }
}
```

- [ ] **Step 2: Test laufen lassen – muss fehlschlagen**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php sh -c "cd app && vendor/bin/phpunit tests/Privacy/ApplicationSignaturePrivacyTest.php"`
Expected: FAIL (Unterschriften bleiben erhalten bzw. Schlüssel `signatures` fehlt).

- [ ] **Step 3: Implementieren**

In `ApplicationPrivacy::redact()` direkt nach dem `UPDATE membership_applications …`:

```php
        (new \Vereinskiste\Members\ApplicationSignatures($this->pdo))->redact($id);
```

In `MemberPrivacy::disclosure()` im Array `$queries` nach `'applications'` ergänzen:

```php
            'signatures'=>"SELECT s.application_id,s.declaration_id,s.version,s.granted,s.snapshot,s.snapshot_hash,s.signed_place,s.signed_at,encode(s.signature_png,'base64') AS signature_png FROM application_signatures s JOIN membership_applications a ON a.id=s.application_id WHERE a.member_id=? ORDER BY s.id",
```

(`snapshot` wird von der vorhandenen Schleife bereits als JSON dekodiert.)

- [ ] **Step 4: Tests laufen lassen – müssen bestehen**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php sh -c "cd app && vendor/bin/phpunit tests/Privacy"`
Expected: `OK`

- [ ] **Step 5: Commit**

```bash
git add app/src/Privacy app/tests/Privacy/ApplicationSignaturePrivacyTest.php
git commit -m "Unterschriften anonymisieren und in die Auskunft aufnehmen

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Einstellungsseiten für Erklärungen

**Files:**
- Create: `app/src/Web/DeclarationController.php`
- Create: `app/templates/settings/declarations.twig`, `app/templates/settings/declaration_form.twig`
- Create: `app/templates/public/_declaration_step.twig`
- Modify: `app/src/Http/Kernel.php` (Routen nach den `/settings/privacy`-Routen)
- Modify: `app/templates/components/settings_tabs.twig`
- Test: `app/tests/Web/DeclarationSettingsPagesTest.php`

**Interfaces:**
- Consumes: `Declarations`.
- Produces: Partial `public/_declaration_step.twig` mit Variablen `declaration` (aus `Declarations::all()`, `title`/`content` bereits gerendert), `answer` (`''|'1'|'yes'|'no'`), `signature` (Data-URL oder `''`), `errors`, `preview` (bool – deaktiviert Eingaben). Routen: `GET /settings/declarations`, `GET|POST /settings/declarations/new`, `GET|POST /settings/declarations/{id}`, `POST /settings/declarations/{id}/move`, `POST /settings/declarations/{id}/toggle`.

- [ ] **Step 1: Failing test schreiben**

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Tests\TestCase;

final class DeclarationSettingsPagesTest extends TestCase
{
    use WebHarness;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->store = new ArraySessionStore();
        $this->app = Kernel::create(['pdo' => $this->pdo, 'session_store' => $this->store, 'require_https' => false, 'security_dir' => $this->securityDir()]);
    }

    public function test_board_creates_edits_moves_and_deactivates_declarations(): void
    {
        $this->loginAs(['admin']);
        $list = (string) $this->get('/settings/declarations')->getBody();
        $this->assertStringContainsString('Antrag auf Mitgliedschaft', $list);
        $this->assertStringContainsString('href="/settings/declarations"', $list);

        $this->assertSame(200, $this->get('/settings/declarations/new')->getStatusCode());
        $res = $this->postForm('/settings/declarations/new', ['title' => 'Beitragsordnung', 'content' => '<p>Ich erkenne die Beitragsordnung an.</p>', 'kind' => 'confirmation', 'signature_required' => '1']);
        $this->assertSame('/settings/declarations', $res->getHeaderLine('Location'));
        $id = (int) $this->pdo->query("SELECT declaration_id FROM application_declaration_versions WHERE title = 'Beitragsordnung'")->fetchColumn();

        $edit = (string) $this->get("/settings/declarations/$id")->getBody();
        $this->assertStringContainsString('data-rich-text', $edit);
        $this->assertStringContainsString('Ich erkenne die Beitragsordnung an.', $edit);
        $this->assertStringContainsString('data-signature-pad', $edit, 'preview shows signature field');

        $this->assertSame(422, $this->postForm("/settings/declarations/$id", ['title' => '', 'content' => '', 'kind' => 'confirmation'])->getStatusCode());
        $this->postForm("/settings/declarations/$id", ['title' => 'Beitragsordnung', 'content' => '<p>Neu</p>', 'kind' => 'consent']);
        $this->assertSame(2, (int) $this->pdo->query("SELECT current_version FROM application_declarations WHERE id = $id")->fetchColumn());

        $this->postForm("/settings/declarations/$id/move", ['direction' => 'up']);
        $this->assertSame(4, (int) $this->pdo->query("SELECT position FROM application_declarations WHERE id = $id")->fetchColumn());
        $this->postForm("/settings/declarations/$id/toggle", ['active' => '0']);
        $this->assertFalse((bool) $this->pdo->query("SELECT active FROM application_declarations WHERE id = $id")->fetchColumn());
        $this->assertSame(404, $this->get('/settings/declarations/999')->getStatusCode());
    }

    public function test_members_role_cannot_manage_declarations(): void
    {
        $this->loginAs(['secretary']);
        $this->assertSame(403, $this->get('/settings/declarations')->getStatusCode());
        $this->assertSame(403, $this->postForm('/settings/declarations/new', ['title' => 'X', 'kind' => 'notice'])->getStatusCode());
    }
}
```

- [ ] **Step 2: Test laufen lassen – muss fehlschlagen**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php sh -c "cd app && vendor/bin/phpunit tests/Web/DeclarationSettingsPagesTest.php"`
Expected: FAIL, 404 auf `/settings/declarations`.

- [ ] **Step 3: Controller schreiben**

`app/src/Web/DeclarationController.php`:

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Club\Profile;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\Declarations;

final class DeclarationController extends AdminController
{
    public function __construct(private Closure $pdo) {}

    public function index(Request $req, Response $res): Response
    {
        return $req->getAttribute('view')->render($res, 'settings/declarations.twig', [
            'declarations' => (new Declarations(($this->pdo)()))->all(),
        ]);
    }

    public function form(Request $req, Response $res, array $args): Response
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $declaration = $id === null ? null : (new Declarations(($this->pdo)()))->find($id);
        if ($id !== null && $declaration === null) {
            return $this->notFound($req);
        }
        return $this->page($req, $res, $declaration, $declaration ?? ['title' => '', 'content' => '', 'kind' => 'confirmation', 'signature_required' => true]);
    }

    public function save(Request $req, Response $res, array $args): Response
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $repo = new Declarations(($this->pdo)());
        $declaration = $id === null ? null : $repo->find($id);
        if ($id !== null && $declaration === null) {
            return $this->notFound($req);
        }
        $input = $this->body($req);
        try {
            $repo->save($id, $input, $this->userId($req));
        } catch (ValidationError $e) {
            $values = ['title' => $this->str($input, 'title'), 'content' => $this->str($input, 'content'),
                'kind' => $this->str($input, 'kind'), 'signature_required' => ($input['signature_required'] ?? '') === '1'];
            return $this->page($req, $res->withStatus(422), $declaration, $values, $e->errors);
        }
        $this->flash($req, 'foundation.saved');
        return $this->redirect('/settings/declarations');
    }

    public function move(Request $req, Response $res, array $args): Response
    {
        (new Declarations(($this->pdo)()))->move((int) $args['id'], $this->str($this->body($req), 'direction'), $this->userId($req));
        return $this->redirect('/settings/declarations');
    }

    public function toggle(Request $req, Response $res, array $args): Response
    {
        try {
            (new Declarations(($this->pdo)()))->setActive((int) $args['id'], $this->str($this->body($req), 'active') === '1', $this->userId($req));
        } catch (ValidationError) {
            return $this->notFound($req);
        }
        $this->flash($req, 'foundation.saved');
        return $this->redirect('/settings/declarations');
    }

    /** @param array<string,mixed>|null $declaration @param array<string,mixed> $values @param array<string,string> $errors */
    private function page(Request $req, Response $res, ?array $declaration, array $values, array $errors = []): Response
    {
        $club = (string) ((new Profile(($this->pdo)()))->get()['data']['name'] ?? '');
        $preview = $declaration === null ? null : ['title' => Declarations::render($declaration['title'], $club, false),
            'content' => Declarations::render($declaration['content'], $club, true)] + $declaration;
        return $req->getAttribute('view')->render($res, 'settings/declaration_form.twig', [
            'declaration' => $declaration, 'values' => $values, 'errors' => $errors, 'preview' => $preview, 'kinds' => Declarations::KINDS,
        ]);
    }
}
```

In `app/src/Http/Kernel.php` nach `$app->post('/settings/privacy', …)`:

```php
        $declarations = new \Vereinskiste\Web\DeclarationController($pdoProvider);
        $app->get('/settings/declarations', [$declarations, 'index'])->add(Auth::requires('settings.manage'));
        $app->get('/settings/declarations/new', [$declarations, 'form'])->add(Auth::requires('settings.manage'));
        $app->post('/settings/declarations/new', [$declarations, 'save'])->add(Auth::requires('settings.manage'));
        $app->get('/settings/declarations/{id:[0-9]+}', [$declarations, 'form'])->add(Auth::requires('settings.manage'));
        $app->post('/settings/declarations/{id:[0-9]+}', [$declarations, 'save'])->add(Auth::requires('settings.manage'));
        $app->post('/settings/declarations/{id:[0-9]+}/move', [$declarations, 'move'])->add(Auth::requires('settings.manage'));
        $app->post('/settings/declarations/{id:[0-9]+}/toggle', [$declarations, 'toggle'])->add(Auth::requires('settings.manage'));
```

In `app/templates/components/settings_tabs.twig` nach dem Eintrag `/settings/privacy`:

```twig
        {'url': '/settings/declarations', 'label': 'declarations.title', 'active': settings_path starts with '/settings/declarations', 'right': 'settings.manage'},
```

- [ ] **Step 4: Templates schreiben**

`app/templates/public/_declaration_step.twig`:

```twig
{# Eine Antragserklaerung; auch als Vorschau in den Einstellungen (preview = true). #}
{% set key = declaration.id %}
<div class="prose-declaration rich-text mb-4">{{ declaration.content|note }}</div>
{% if declaration.kind == 'confirmation' %}
<div class="check mb-4">
    <input class="check-input{% if errors['declaration_' ~ key] is defined %} is-invalid{% endif %}" type="checkbox" id="declaration-{{ key }}"
           name="declaration[{{ key }}]" value="1"{% if answer == '1' %} checked{% endif %}{% if preview %} disabled{% else %} required{% endif %}>
    <label class="check-label font-semibold" for="declaration-{{ key }}">{{ t('declarations.confirm_label') }}</label>
</div>
{% elseif declaration.kind == 'consent' %}
<fieldset class="mb-4 grid gap-2 sm:grid-cols-2">
    <legend class="sr-only">{{ declaration.title }}</legend>
    {% for choice in ['yes', 'no'] %}
    <label class="choice-card{% if errors['declaration_' ~ key] is defined %} is-invalid{% endif %}" for="declaration-{{ key }}-{{ choice }}">
        <input class="check-input" type="radio" id="declaration-{{ key }}-{{ choice }}" name="declaration[{{ key }}]" value="{{ choice }}"
               {% if answer == choice %}checked{% endif %}{% if preview %} disabled{% else %} required{% endif %}>
        <span>{{ t('declarations.consent_' ~ choice) }}</span>
    </label>
    {% endfor %}
</fieldset>
{% endif %}
{% if errors['declaration_' ~ key] is defined %}<p class="field-error" role="alert">{{ t(errors['declaration_' ~ key]) }}</p>{% endif %}
{% if declaration.signature_required %}
<div class="signature-pad{% if errors['signature_' ~ key] is defined %} is-invalid{% endif %}" data-signature-pad{% if not preview %} data-signature-required{% endif %}>
    <span class="label">{{ t('declarations.signature') }}</span>
    <div class="signature-surface">
        <canvas class="signature-canvas" role="img" aria-label="{{ t('declarations.signature_hint') }}"></canvas>
        <span class="signature-hint" aria-hidden="true">{{ t('declarations.signature_hint') }}</span>
    </div>
    <input type="hidden" name="signature[{{ key }}]" value="{{ signature }}" data-signature-value{% if preview %} disabled{% endif %}>
    <div class="mt-2 flex flex-wrap gap-2">
        <button type="button" class="btn btn-sm" data-signature-clear>{{ t('declarations.signature_clear') }}</button>
        <button type="button" class="btn btn-sm" data-signature-enlarge>{{ t('declarations.signature_enlarge') }}</button>
    </div>
    {% if errors['signature_' ~ key] is defined %}<p class="field-error" role="alert">{{ t(errors['signature_' ~ key]) }}</p>{% endif %}
</div>
{% endif %}
```

`app/templates/settings/declarations.twig`:

```twig
{% extends 'layout.twig' %}
{% block content %}
<div class="mx-auto w-full max-w-7xl">
    {% include 'components/settings_tabs.twig' %}
    <div class="page-header">
        <p class="text-muted max-w-3xl mb-0">{{ t('declarations.help') }}</p>
        <a class="btn btn-primary" href="/settings/declarations/new">{{ t('declarations.new') }}</a>
    </div>
    <div class="panel overflow-x-auto">
        <table class="data-table table-cards">
            <thead><tr><th>{{ t('declarations.field.position') }}</th><th>{{ t('declarations.field.title') }}</th><th>{{ t('declarations.field.kind') }}</th><th>{{ t('declarations.field.signature_required') }}</th><th>{{ t('declarations.field.version') }}</th><th></th></tr></thead>
            <tbody>
            {% for d in declarations %}
            <tr{% if not d.active %} class="text-muted"{% endif %}>
                <td data-label="{{ t('declarations.field.position') }}">
                    <form method="post" action="/settings/declarations/{{ d.id }}/move" class="inline-flex gap-1">{{ csrf_field() }}
                        <button class="btn btn-sm" name="direction" value="up" aria-label="{{ t('declarations.move_up') }}"{% if loop.first %} disabled{% endif %}>↑</button>
                        <button class="btn btn-sm" name="direction" value="down" aria-label="{{ t('declarations.move_down') }}"{% if loop.last %} disabled{% endif %}>↓</button>
                    </form>
                </td>
                <td data-label="{{ t('declarations.field.title') }}"><a href="/settings/declarations/{{ d.id }}">{{ d.title }}</a>{% if not d.active %} · {{ t('declarations.inactive') }}{% endif %}</td>
                <td data-label="{{ t('declarations.field.kind') }}">{{ t('declarations.kind.' ~ d.kind) }}</td>
                <td data-label="{{ t('declarations.field.signature_required') }}">{{ d.signature_required ? t('common.yes') : t('common.no') }}</td>
                <td data-label="{{ t('declarations.field.version') }}">{{ d.version }}</td>
                <td>
                    <form method="post" action="/settings/declarations/{{ d.id }}/toggle">{{ csrf_field() }}
                        <input type="hidden" name="active" value="{{ d.active ? '0' : '1' }}">
                        <button class="btn btn-sm">{{ t(d.active ? 'declarations.deactivate' : 'declarations.activate') }}</button>
                    </form>
                </td>
            </tr>
            {% else %}
            <tr><td colspan="6">{{ t('declarations.none') }}</td></tr>
            {% endfor %}
            </tbody>
        </table>
    </div>
</div>
{% endblock %}
```

Prüfen, dass `common.yes`/`common.no` existieren (`grep -n "'common.yes'" app/src/Text/de/*.php`); fehlen sie, in `declarations.php` als `'declarations.yes' => 'Ja'`, `'declarations.no' => 'Nein'` anlegen und im Template verwenden.

`app/templates/settings/declaration_form.twig`:

```twig
{% extends 'layout.twig' %}
{% block content %}
<div class="mx-auto w-full max-w-7xl">
    {% include 'components/settings_tabs.twig' %}
    <h2 class="h4 mb-4">{{ t(declaration ? 'declarations.edit' : 'declarations.new') }}{% if declaration %} · {{ t('declarations.field.version') }} {{ declaration.version }}{% endif %}</h2>
    <div class="grid gap-6 lg:grid-cols-2">
        <form method="post" action="/settings/declarations/{{ declaration ? declaration.id : 'new' }}" class="panel panel-body" novalidate>
            {{ csrf_field() }}
            <label class="label required" for="declaration-title">{{ t('declarations.field.title') }}</label>
            <input class="input mb-1{% if errors.title is defined %} is-invalid{% endif %}" id="declaration-title" name="title" value="{{ values.title }}" maxlength="200" required>
            {% if errors.title is defined %}<p class="field-error">{{ t(errors.title) }}</p>{% endif %}
            <label class="label required mt-4" for="declaration-kind">{{ t('declarations.field.kind') }}</label>
            <select class="input{% if errors.kind is defined %} is-invalid{% endif %}" id="declaration-kind" name="kind">
                {% for kind in kinds %}<option value="{{ kind }}"{% if values.kind == kind %} selected{% endif %}>{{ t('declarations.kind.' ~ kind) }}</option>{% endfor %}
            </select>
            <div class="check mt-4">
                <input class="check-input" type="checkbox" id="declaration-signature" name="signature_required" value="1"{% if values.signature_required %} checked{% endif %}>
                <label class="check-label" for="declaration-signature">{{ t('declarations.field.signature_required') }}</label>
            </div>
            <label class="label mt-4" for="declaration-content">{{ t('declarations.field.content') }}</label>
            <textarea class="input{% if errors.content is defined %} is-invalid{% endif %}" id="declaration-content" name="content" rows="14" data-rich-text>{{ values.content|e }}</textarea>
            {% if errors.content is defined %}<p class="field-error">{{ t(errors.content) }}</p>{% endif %}
            <div class="mt-6 flex gap-2">
                <button class="btn btn-primary">{{ t('common.save') }}</button>
                <a class="btn" href="/settings/declarations">{{ t('common.back') }}</a>
            </div>
        </form>
        {% if preview %}
        <section class="panel panel-body" aria-labelledby="declaration-preview">
            <h3 id="declaration-preview" class="panel-title">{{ t('declarations.preview') }}</h3>
            <h4 class="h5">{{ preview.title }}</h4>
            {% include 'public/_declaration_step.twig' with {declaration: preview, answer: '', signature: '', errors: {}, preview: true} %}
        </section>
        {% endif %}
    </div>
</div>
{% endblock %}
{% block scripts %}<script src="{{ asset('vendor/tinymce/tinymce.min.js') }}"></script><script src="{{ asset('rich-text.js') }}"></script><script src="{{ asset('signature-pad.js') }}"></script>{% endblock %}
```

Prüfen, dass `common.save` existiert (`grep -n "'common.save'" app/src/Text/de/*.php`); sonst den Schlüssel verwenden, den `settings/club.twig` für „Speichern“ nutzt.

- [ ] **Step 5: Test laufen lassen – muss bestehen**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php sh -c "cd app && vendor/bin/phpunit tests/Web/DeclarationSettingsPagesTest.php tests/Text"`
Expected: `OK`

- [ ] **Step 6: Commit**

```bash
git add app/src/Web/DeclarationController.php app/src/Http/Kernel.php app/templates app/src/Text/de/declarations.php app/tests/Web/DeclarationSettingsPagesTest.php
git commit -m "Einstellungsseiten fuer Antragserklaerungen

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Unterschriftsfeld (`signature-pad.js`)

**Files:**
- Create: `public/assets/signature-pad.js`
- Modify: `app/styles/app.css` (Komponenten `signature-pad`, `signature-surface`, `signature-canvas`, `signature-hint`, `signature-dialog`, `choice-card`, `field-error` falls nicht vorhanden)
- Test: `tests/signature-pad.test.cjs`

**Interfaces:**
- Consumes: Markup aus `public/_declaration_step.twig` (`[data-signature-pad]`, `canvas`, `[data-signature-value]`, `[data-signature-clear]`, `[data-signature-enlarge]`).
- Produces: `window.VkSignaturePad.init(root?: ParentNode)`; jedes Pad feuert `signature:change` (bubbles) mit `detail.value`; Pad bekommt Klasse `has-signature`, wenn eine Unterschrift vorhanden ist. Export: PNG 900 × 240 (Seitenverhältnis 3,75 : 1), transparenter Grund, Strichfarbe `#111827`.

- [ ] **Step 1: Failing test schreiben**

`tests/signature-pad.test.cjs`:

```js
const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {JSDOM} = require('jsdom');

const markup = `<form><div class="signature-pad" data-signature-pad data-signature-required>
  <div class="signature-surface"><canvas class="signature-canvas"></canvas></div>
  <input type="hidden" name="signature[1]" value="" data-signature-value>
  <button type="button" data-signature-clear>Löschen</button>
  <button type="button" data-signature-enlarge>Größer</button></div></form>`;

function setup(value = '') {
    const dom = new JSDOM(markup.replace('value=""', `value="${value}"`), {runScripts: 'outside-only', pretendToBeVisual: true});
    const w = dom.window;
    const calls = [];
    w.HTMLCanvasElement.prototype.getContext = function () {
        return {setTransform() {}, clearRect() {}, beginPath() {}, moveTo() {}, lineTo() {}, quadraticCurveTo() {}, stroke() {}, drawImage() {},
            set lineWidth(v) {}, set lineCap(v) {}, set lineJoin(v) {}, set strokeStyle(v) {}, scale() {}};
    };
    w.HTMLCanvasElement.prototype.toDataURL = function () { calls.push([this.width, this.height]); return 'data:image/png;base64,AAAA'; };
    w.HTMLCanvasElement.prototype.getBoundingClientRect = () => ({left: 0, top: 0, width: 375, height: 100});
    w.HTMLCanvasElement.prototype.setPointerCapture = () => {};
    require('./browser-texts.cjs')(w);
    w.eval(readFileSync(require.resolve('../public/assets/signature-pad.js'), 'utf8'));
    return {w, d: w.document, calls};
}

function draw(w, canvas) {
    const ev = (type, x, y) => canvas.dispatchEvent(new w.MouseEvent(type, {bubbles: true, clientX: x, clientY: y, buttons: 1}));
    ev('pointerdown', 10, 50); ev('pointermove', 60, 40); ev('pointermove', 120, 60); ev('pointerup', 120, 60);
}

test('drawing writes a PNG data URL and fires a change event', () => {
    const {w, d, calls} = setup();
    try {
        const pad = d.querySelector('[data-signature-pad]'), hidden = d.querySelector('[data-signature-value]');
        let changes = 0; pad.addEventListener('signature:change', () => changes++);
        draw(w, d.querySelector('canvas'));
        assert.equal(hidden.value, 'data:image/png;base64,AAAA');
        assert.deepEqual(calls.at(-1), [900, 240]);
        assert.ok(pad.classList.contains('has-signature'));
        assert.equal(changes, 1);
    } finally { w.close(); }
});

test('a tap without movement does not count as signature', () => {
    const {w, d} = setup();
    try {
        const canvas = d.querySelector('canvas');
        canvas.dispatchEvent(new w.MouseEvent('pointerdown', {bubbles: true, clientX: 10, clientY: 10}));
        canvas.dispatchEvent(new w.MouseEvent('pointerup', {bubbles: true, clientX: 10, clientY: 10}));
        assert.equal(d.querySelector('[data-signature-value]').value, '');
    } finally { w.close(); }
});

test('clear empties the value and restored signatures count as present', () => {
    const {w, d} = setup('data:image/png;base64,BBBB');
    try {
        const pad = d.querySelector('[data-signature-pad]'), hidden = d.querySelector('[data-signature-value]');
        assert.ok(pad.classList.contains('has-signature'));
        d.querySelector('[data-signature-clear]').click();
        assert.equal(hidden.value, '');
        assert.ok(!pad.classList.contains('has-signature'));
    } finally { w.close(); }
});

test('enlarge opens a dialog and apply keeps strokes drawn there', () => {
    const {w, d} = setup();
    try {
        d.querySelector('[data-signature-enlarge]').click();
        const dialog = d.querySelector('.signature-dialog');
        assert.ok(dialog && !dialog.hidden);
        draw(w, dialog.querySelector('canvas'));
        dialog.querySelector('[data-signature-apply]').click();
        assert.ok(dialog.hidden);
        assert.equal(d.querySelector('[data-signature-value]').value, 'data:image/png;base64,AAAA');
    } finally { w.close(); }
});
```

- [ ] **Step 2: Test laufen lassen – muss fehlschlagen**

Run: `node --test tests/signature-pad.test.cjs`
Expected: FAIL, `ENOENT … signature-pad.js`.

- [ ] **Step 3: Implementieren**

`public/assets/signature-pad.js`:

```js
// Unterschriftsfeld fuer Maus, Finger und Stift. Striche werden normiert (0..1) gespeichert,
// damit Feld und Vollbild-Dialog dieselbe Unterschrift zeigen; Export als PNG 900 x 240.
(function () {
    'use strict';
    var RATIO = 3.75, EXPORT_W = 900, EXPORT_H = 240, INK = '#111827';
    var texts = window.VkTexts || {};
    function text(key, fallback) { return texts[key] || fallback; }

    function Pad(root) {
        this.root = root;
        this.canvas = root.querySelector('canvas');
        this.input = root.querySelector('[data-signature-value]');
        this.strokes = [];
        this.base = null; // wiederhergestellte Unterschrift (Data-URL)
        if (this.input.value) { this.base = this.input.value; this.loadBase(); }
        this.bind(this.canvas);
        var self = this;
        root.querySelector('[data-signature-clear]').addEventListener('click', function () { self.clear(); });
        var enlarge = root.querySelector('[data-signature-enlarge]');
        if (enlarge) { enlarge.addEventListener('click', function () { self.openDialog(); }); }
        window.addEventListener('resize', function () { self.render(self.canvas); });
        this.render(this.canvas);
        this.mark();
    }

    Pad.prototype.loadBase = function () {
        if (typeof Image === 'undefined') { return; }
        var self = this, img = new Image();
        img.onload = function () { self.baseImage = img; self.render(self.canvas); };
        img.src = this.base;
    };

    Pad.prototype.size = function (canvas) {
        var rect = canvas.getBoundingClientRect();
        var width = rect.width || canvas.clientWidth || 600;
        return {width: width, height: width / RATIO};
    };

    Pad.prototype.render = function (canvas, exportSize) {
        var size = exportSize || this.size(canvas);
        var dpr = exportSize ? 1 : (window.devicePixelRatio || 1);
        canvas.width = Math.round(size.width * dpr);
        canvas.height = Math.round(size.height * dpr);
        if (!exportSize) { canvas.style.height = size.height + 'px'; }
        var ctx = canvas.getContext('2d');
        if (!ctx) { return; }
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, size.width, size.height);
        if (this.baseImage) { ctx.drawImage(this.baseImage, 0, 0, size.width, size.height); }
        ctx.lineWidth = Math.max(2, size.width / 250);
        ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = INK;
        for (var i = 0; i < this.strokes.length; i++) { this.drawStroke(ctx, this.strokes[i], size); }
    };

    Pad.prototype.drawStroke = function (ctx, points, size) {
        if (points.length < 2) { return; }
        ctx.beginPath();
        ctx.moveTo(points[0][0] * size.width, points[0][1] * size.height);
        for (var i = 1; i < points.length - 1; i++) {
            var mx = (points[i][0] + points[i + 1][0]) / 2, my = (points[i][1] + points[i + 1][1]) / 2;
            ctx.quadraticCurveTo(points[i][0] * size.width, points[i][1] * size.height, mx * size.width, my * size.height);
        }
        var last = points[points.length - 1];
        ctx.lineTo(last[0] * size.width, last[1] * size.height);
        ctx.stroke();
    };

    Pad.prototype.bind = function (canvas) {
        var self = this, current = null;
        function point(e) {
            var rect = canvas.getBoundingClientRect();
            var w = rect.width || 1, h = rect.height || w / RATIO;
            return [Math.min(1, Math.max(0, (e.clientX - rect.left) / w)), Math.min(1, Math.max(0, (e.clientY - rect.top) / h))];
        }
        canvas.addEventListener('pointerdown', function (e) {
            if (e.button !== undefined && e.button > 0) { return; }
            e.preventDefault();
            if (canvas.setPointerCapture && e.pointerId !== undefined) { canvas.setPointerCapture(e.pointerId); }
            current = [point(e)];
            self.strokes.push(current);
        });
        canvas.addEventListener('pointermove', function (e) {
            if (!current) { return; }
            e.preventDefault();
            current.push(point(e));
            self.render(canvas);
        });
        function end() {
            if (!current) { return; }
            if (current.length < 2) { self.strokes.pop(); }
            current = null;
            if (canvas === self.canvas) { self.commit(); }
        }
        canvas.addEventListener('pointerup', end);
        canvas.addEventListener('pointercancel', end);
    };

    Pad.prototype.hasInk = function () { return this.strokes.length > 0 || !!this.base; };

    Pad.prototype.commit = function () {
        var value = '';
        if (this.hasInk()) {
            var out = document.createElement('canvas');
            this.render(out, {width: EXPORT_W, height: EXPORT_H});
            value = out.toDataURL('image/png');
        }
        this.input.value = value;
        this.render(this.canvas);
        this.mark();
        this.root.dispatchEvent(new CustomEvent('signature:change', {bubbles: true, detail: {value: value}}));
    };

    Pad.prototype.mark = function () {
        this.root.classList.toggle('has-signature', this.input.value !== '');
        if (this.input.value !== '') { this.root.classList.remove('is-invalid'); }
    };

    Pad.prototype.clear = function () {
        this.strokes = []; this.base = null; this.baseImage = null;
        this.commit();
    };

    Pad.prototype.openDialog = function () {
        var self = this;
        if (!this.dialog) {
            var d = document.createElement('div');
            d.className = 'signature-dialog';
            d.setAttribute('role', 'dialog');
            d.setAttribute('aria-modal', 'true');
            d.innerHTML = '<div class="signature-dialog-body"><div class="signature-surface"><canvas class="signature-canvas"></canvas></div>'
                + '<div class="mt-3 flex flex-wrap justify-end gap-2"><button type="button" class="btn" data-signature-dialog-clear></button>'
                + '<button type="button" class="btn btn-primary" data-signature-apply></button></div></div>';
            d.querySelector('[data-signature-dialog-clear]').textContent = text('js.signature_clear', 'Löschen');
            d.querySelector('[data-signature-apply]').textContent = text('js.signature_apply', 'Übernehmen');
            document.body.appendChild(d);
            this.dialog = d;
            this.bind(d.querySelector('canvas'));
            d.querySelector('[data-signature-dialog-clear]').addEventListener('click', function () {
                self.strokes = []; self.base = null; self.baseImage = null; self.render(d.querySelector('canvas'));
            });
            d.querySelector('[data-signature-apply]').addEventListener('click', function () {
                d.hidden = true; document.documentElement.classList.remove('overflow-hidden'); self.commit();
            });
        }
        this.dialog.hidden = false;
        document.documentElement.classList.add('overflow-hidden');
        this.render(this.dialog.querySelector('canvas'));
    };

    function init(root) {
        (root || document).querySelectorAll('[data-signature-pad]').forEach(function (el) {
            if (!el.vkSignaturePad) { el.vkSignaturePad = new Pad(el); }
        });
    }
    window.VkSignaturePad = {init: init};
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { init(); }); } else { init(); }
})();
```

Texte für den Dialog in `app/src/Text/de/declarations.php` ergänzen und – wie die vorhandenen `js.*`-Schlüssel – in die Liste der an den Browser übergebenen Texte aufnehmen (`grep -rn "js.datum_auswahlen" app/src` zeigt die Stelle, an der `interface-texts` befüllt wird):

```php
    'js.signature_clear' => 'Löschen',
    'js.signature_apply' => 'Übernehmen',
```

Prüfen, wie `tests/browser-texts.cjs` die Texte bereitstellt; `text()` in `signature-pad.js` an dieselbe Quelle anpassen (z. B. `window.VkInterfaceTexts` statt `window.VkTexts`), falls der Name abweicht.

In `app/styles/app.css` im Komponenten-Layer ergänzen:

```css
    .signature-pad { display: block; margin-bottom: 1rem; }
    .signature-surface { position: relative; border: 1px dashed var(--color-line); border-radius: 0.75rem; background: #fff; }
    .signature-pad.is-invalid .signature-surface { border-color: var(--color-danger); }
    .signature-canvas { display: block; width: 100%; aspect-ratio: 3.75 / 1; touch-action: none; cursor: crosshair; }
    .signature-hint { position: absolute; left: 1rem; right: 1rem; bottom: 22%; border-top: 1px solid #cbd5e1; padding-top: 0.25rem; color: #64748b; font-size: 0.75rem; pointer-events: none; }
    .signature-pad.has-signature .signature-hint { color: transparent; }
    .signature-dialog { position: fixed; inset: 0; z-index: 60; display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgb(0 0 0 / 0.6); }
    .signature-dialog-body { width: min(100%, 60rem); border-radius: 1rem; background: var(--color-surface); padding: 1rem; }
    .choice-card { display: flex; align-items: center; gap: 0.75rem; min-height: 2.75rem; border: 1px solid var(--color-line); border-radius: 0.75rem; padding: 0.75rem 1rem; cursor: pointer; }
    .choice-card:has(input:checked) { border-color: var(--color-primary); background: color-mix(in oklab, var(--color-primary) 8%, transparent); }
    .choice-card.is-invalid { border-color: var(--color-danger); }
    .field-error { margin-top: 0.25rem; color: var(--color-danger); font-size: 0.875rem; }
```

Vorher `grep -n "\-\-color-danger\|\.field-error" app/styles/app.css` – existiert `.field-error` schon oder heißt die Fehlerfarbe anders, die vorhandenen Namen verwenden.

- [ ] **Step 4: Test laufen lassen – muss bestehen**

Run: `node --test tests/signature-pad.test.cjs`
Expected: `pass 4`, `fail 0`

- [ ] **Step 5: Commit**

```bash
git add public/assets/signature-pad.js tests/signature-pad.test.cjs app/styles/app.css app/src
git commit -m "Unterschriftsfeld fuer Maus und Touch

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Straße und Hausnummer überall getrennt

Mitglieder haben bereits `house_number`. Gemeinsam ist die Adresse noch im Online-Antrag (inkl. Übernahme ins Mitglied und Antragsliste), bei Finanzkontakten (inkl. CSV-Export) und in der Vereinsanschrift (`club_profile`). Vorhandene Daten werden nur dort aufgeteilt, wo die Adresse eindeutig auf eine Hausnummer endet und noch keine Hausnummer gesetzt ist.

**Files:**
- Create: `app/migrations/0023_split_house_numbers.sql`
- Modify: `app/src/Members/ApplicationRepository.php` (`FIELDS`, `submit()`, `approve()`)
- Modify: `app/src/Contacts/ContactRepository.php:16` (`FIELDS`), `app/src/Contacts/ContactExport.php:18,26`
- Modify: `app/src/Club/Profile.php:15` (`FIELDS`)
- Modify: `app/src/Text/de/foundation.php` (`foundation.field.address` → „Straße“)
- Modify: `app/templates/members/applications.twig:188`
- Test: `app/tests/Db/SplitHouseNumbersMigrationTest.php`, Ergänzung in `app/tests/Members/ApplicationRepositoryTest.php`

**Interfaces:**
- Produces: Antragsdaten, Kontakte und Vereinsprofil enthalten `house_number` (max. 20 Zeichen) direkt nach `address`. `address` bedeutet überall „Straße“.

- [ ] **Step 1: Failing tests schreiben**

`app/tests/Db/SplitHouseNumbersMigrationTest.php`:

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Db;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Tests\TestCase;

final class SplitHouseNumbersMigrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        $migrator = new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations');
        // bis einschliesslich 0022 migrieren, Daten anlegen, dann 0023 ausfuehren
        $this->pdo->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
        $dir = sys_get_temp_dir() . '/vk-mig-' . bin2hex(random_bytes(4));
        mkdir($dir);
        foreach (glob(dirname(__DIR__, 2) . '/migrations/*.sql') as $file) {
            if (basename($file) < '0023') { copy($file, $dir . '/' . basename($file)); }
        }
        (new Migrator($this->pdo, $dir))->migrate();
        array_map('unlink', glob($dir . '/*.sql')); rmdir($dir);
        $this->migrator = $migrator;
    }

    private Migrator $migrator;

    public function test_unambiguous_addresses_are_split_others_kept(): void
    {
        $insert = $this->pdo->prepare("INSERT INTO finance_contacts (kind, name, data) VALUES ('person', ?, ?::jsonb)");
        foreach (['Musterstraße 12' => 'a', 'Am Weiher 3a' => 'b', 'Hauptstr. 10-12' => 'c', 'Postfach' => 'd', 'Ring 5' => 'e'] as $address => $name) {
            $data = ['address' => $address] + ($name === 'e' ? ['house_number' => '7'] : []);
            $insert->execute([$name, json_encode($data, JSON_UNESCAPED_UNICODE)]);
        }
        $this->pdo->exec("UPDATE club_profile SET data = data || '{\"address\":\"Schillerstraße 1\"}'::jsonb WHERE id = 1");
        $this->migrator->migrate();

        $rows = $this->pdo->query("SELECT name, data->>'address' AS a, data->>'house_number' AS h FROM finance_contacts ORDER BY name")->fetchAll(PDO::FETCH_NUM);
        $this->assertSame([
            ['a', 'Musterstraße', '12'], ['b', 'Am Weiher', '3a'], ['c', 'Hauptstr.', '10-12'], ['d', 'Postfach', null], ['e', 'Ring 5', '7'],
        ], $rows);
        $this->assertSame(['Schillerstraße', '1'], $this->pdo->query("SELECT data->>'address', data->>'house_number' FROM club_profile")->fetch(PDO::FETCH_NUM));
    }
}
```

In `app/tests/Members/ApplicationRepositoryTest.php` ergänzen:

```php
    public function test_house_number_is_stored_and_transferred_to_member(): void
    {
        $repo = new ApplicationRepository($this->pdo);
        $invite = $repo->createInvite(1, 'Test', 7);
        $id = $repo->submit($invite['token'], ['first_name' => 'E', 'last_name' => 'M', 'email' => 'e@example.org',
            'address' => 'Musterstraße', 'house_number' => '12a', 'zip' => '91054', 'city' => 'Erlangen'] + ApplicationInput::declarations($this->pdo));
        $this->assertSame('12a', $repo->findApplication($id)['data']['house_number']);
        $member = $repo->approve($id, 1);
        $data = json_decode((string) $this->pdo->query("SELECT data FROM members WHERE id = $member")->fetchColumn(), true);
        $this->assertSame(['Musterstraße', '12a'], [$data['address'], $data['house_number']]);
    }
```

(Falls `setUp()` dort keinen Benutzer mit ID 1 anlegt, wie in Task 4 `INSERT INTO users …` ergänzen.)

- [ ] **Step 2: Tests laufen lassen – müssen fehlschlagen**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php sh -c "cd app && vendor/bin/phpunit tests/Db/SplitHouseNumbersMigrationTest.php tests/Members/ApplicationRepositoryTest.php"`
Expected: FAIL (`house_number` fehlt).

- [ ] **Step 3: Migration schreiben**

`app/migrations/0023_split_house_numbers.sql`:

```sql
-- Strasse und Hausnummer getrennt speichern. Nur eindeutige Faelle aufteilen:
-- Adresse endet auf eine Hausnummer (z. B. 12, 3a, 10-12, 4/1) und es gibt noch keine Hausnummer.
CREATE FUNCTION vk_split_house_number(data jsonb) RETURNS jsonb LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE WHEN m IS NULL THEN data
        ELSE data || jsonb_build_object('address', m[1], 'house_number', m[2]) END
    FROM (SELECT CASE WHEN coalesce(data->>'house_number', '') = ''
        THEN regexp_match(btrim(coalesce(data->>'address', '')), '^(.*\S)\s+(\d+\s?[a-zA-Z]?(?:\s?[-/]\s?\d+\s?[a-zA-Z]?)?)$') END AS m) s
$$;
UPDATE members SET data = vk_split_house_number(data) WHERE anonymized_at IS NULL AND vk_split_house_number(data) <> data;
UPDATE finance_contacts SET data = vk_split_house_number(data) WHERE vk_split_house_number(data) <> data;
UPDATE club_profile SET data = vk_split_house_number(data) WHERE vk_split_house_number(data) <> data;
UPDATE membership_applications SET data = vk_split_house_number(data)
    WHERE status = 'pending' AND anonymized_at IS NULL AND vk_split_house_number(data) <> data;
DROP FUNCTION vk_split_house_number(jsonb);
```

Vorher prüfen, ob `members` und `finance_contacts` eine `version`-Spalte für Optimistic Locking haben (`grep -n "version" app/migrations/0008_foundation.sql app/migrations/0011_members.sql`); falls ja, in den beiden `UPDATE`s `, version = version + 1` ergänzen, damit offene Bearbeitungsformulare einen Konflikt melden statt alte Daten zurückzuschreiben.

- [ ] **Step 4: Code anpassen**

`ApplicationRepository::FIELDS`: nach `'address' => 200,` ergänzen `'house_number' => 20,`.
In `submit()` nach der `$address`-Zeile:

```php
            $houseNumber = mb_substr(trim((string) ($input['house_number'] ?? '')), 0, 20);
```

und in `$data` nach `'address' => $address,`: `'house_number' => $houseNumber,`.
In `approve()` in `$memberInput` nach `'address' => …`: `'house_number' => $data['house_number'] ?? '',`.

`ContactRepository::FIELDS` und `Profile::FIELDS`: nach `'address' => 200,` jeweils `'house_number' => 20,` einfügen. Formulare und Detailansichten rendern die Felder generisch aus `FIELDS` (`components/foundation_fields.twig`, `contacts/detail.twig`), das Label `foundation.field.house_number` existiert bereits.

`app/src/Text/de/foundation.php`: `'foundation.field.address' => 'Anschrift'` → `'foundation.field.address' => 'Straße'`.

`ContactExport.php:18` Kopfzeile: `'Adresse'` → `'Straße', 'Hausnummer'`; Zeile 26: `['address', 'house_number', 'zip', 'city', 'country', 'email', 'phone']`.

`members/applications.twig:188`: `{{ a.data.address }}` → `{{ a.data.address }} {{ a.data.house_number|default('') }}`.

Bestehende Tests, die die CSV-Kopfzeile der Kontakte prüfen (`grep -rn "Adresse" app/tests`), auf `Straße;Hausnummer` umstellen.

- [ ] **Step 5: Tests laufen lassen – müssen bestehen**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php sh -c "cd app && vendor/bin/phpunit tests/Db tests/Members tests/Web/FoundationPagesTest.php tests/Text"`
Expected: `OK`

- [ ] **Step 6: Commit**

```bash
git add app/migrations/0023_split_house_numbers.sql app/src app/templates/members/applications.twig app/tests
git commit -m "Strasse und Hausnummer ueberall getrennt

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Stepper-Formular und Layout

**Files:**
- Modify: `app/templates/public/membership_application.twig` (komplett neu)
- Modify: `app/templates/layout.twig:94` (Container für `/antrag/`)
- Modify: `app/src/Web/MembershipApplicationController.php` (`renderForm()`)
- Create: `public/assets/application-stepper.js`
- Test: `app/tests/Web/MembershipApplicationPagesTest.php` (neue Testmethoden), `tests/application-stepper.test.cjs`

**Interfaces:**
- Consumes: `Declarations::active()`, `Declarations::render()`, Partial `public/_declaration_step.twig`, `signature-pad.js`.
- Produces: Markup-Vertrag für den Stepper: `form[data-application-stepper]`, Abschnitte `section[data-step][id="schritt-N"]` mit `h2[tabindex="-1"]`, Abschnitte mit Serverfehler tragen `data-step-error`, Buttons `[data-step-next]`, `[data-step-prev]`, Statustext `[data-stepper-status]`, Balken `[data-stepper-bar]`, Absenden `button[data-stepper-submit][disabled]`, Zusammenfassung `[data-summary-choice="<id>"]`, `img[data-summary-signature="<id>"]`, Sprunglinks `a[data-step-goto="<index>"]`.

- [ ] **Step 1: Failing PHP-Tests schreiben**

In `app/tests/Web/MembershipApplicationPagesTest.php` ergänzen:

```php
    public function test_form_renders_one_step_per_active_declaration(): void
    {
        $invite = (new ApplicationRepository($this->pdo))->createInvite(1, 'Wintersemester', 7, null, true);
        $html = (string) $this->get('/antrag/' . $invite['token'])->getBody();
        $this->assertStringContainsString('data-application-stepper', $html);
        $this->assertSame(1 + 1 + 4 + 1, substr_count($html, 'data-step '), 'person, SEPA, 4 declarations, finish');
        $this->assertSame(3, substr_count($html, 'data-signature-pad'));
        $this->assertStringContainsString('Ich willige nicht ein', $html);
        $this->assertStringContainsString('name="signed_place"', $html);
        $this->assertStringNotContainsString('System-E-Mail-Versand', $html);
        $this->assertStringNotContainsString('JJJJ-MM-TT', $html);
        $this->assertStringContainsString('max-w-3xl', $html);
        $this->assertSame(1, substr_count($html, 'Vereinskiste Logo') + substr_count($html, 'size-12'), 'only the page logo, none in the card');
    }

    public function test_server_errors_keep_answers_and_signatures_and_mark_step(): void
    {
        $invite = (new ApplicationRepository($this->pdo))->createInvite(1, 'Test', 7);
        $input = ['first_name' => 'Erika', 'last_name' => 'Muster', 'email' => 'kaputt'] + ApplicationInput::declarations($this->pdo, 'yes');
        $res = $this->postForm('/antrag/' . $invite['token'], $input);
        $this->assertSame(422, $res->getStatusCode());
        $html = (string) $res->getBody();
        $first = array_key_first($input['signature']);
        $this->assertStringContainsString('value="' . $input['signature'][$first] . '"', $html);
        $this->assertMatchesRegularExpression('/id="schritt-1"[^>]*data-step-error/', $html);
        $this->assertStringContainsString('value="yes"' . "\n" . '               checked', $html);
    }
```

(`use Vereinskiste\Tests\Support\ApplicationInput;` ist seit Task 4 vorhanden.)

Hinweis zum letzten Assert: Er hängt an der Formatierung im Partial. Wenn er zu fragil ist, stattdessen prüfen: `assertMatchesRegularExpression('/value="yes"\s+checked/', $html)`.

- [ ] **Step 2: Tests laufen lassen – müssen fehlschlagen**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php sh -c "cd app && vendor/bin/phpunit --filter 'test_form_renders_one_step|test_server_errors_keep' tests/Web/MembershipApplicationPagesTest.php"`
Expected: FAIL (`data-application-stepper` fehlt).

- [ ] **Step 3: Controller anpassen**

In `MembershipApplicationController::renderForm()` die Defaults `'consent_statute'`, `'consent_privacy'` entfernen und vor dem `render` ergänzen:

```php
        $pdo = ($this->pdo)();
        $club = (string) ((new \Vereinskiste\Club\Profile($pdo))->get()['data']['name'] ?? '');
        $declarations = array_map(static fn(array $d): array => ['title' => \Vereinskiste\Members\Declarations::render($d['title'], $club, false),
            'content' => \Vereinskiste\Members\Declarations::render($d['content'], $club, true)] + $d,
            (new \Vereinskiste\Members\Declarations($pdo))->active());
        $strings = static fn(mixed $values): array => is_array($values) ? array_map(
            static fn(mixed $v): string => is_string($v) && mb_check_encoding($v, 'UTF-8') ? $v : '',
            array_filter($values, static fn(mixed $k): bool => is_int($k) || ctype_digit((string) $k), ARRAY_FILTER_USE_KEY)) : [];
```

und im Render-Array `'consent_documents' => …` ersetzen durch:

```php
            'declarations' => $declarations,
            'answers' => $strings($input['declaration'] ?? null),
            'signatures' => $strings($input['signature'] ?? null),
            'today' => (new \DateTimeImmutable('today'))->format('d.m.Y'),
```

Die Schleife über `$input ?? $defaults` macht Arrays zu `''`; das bleibt so, `answers`/`signatures` werden separat übergeben.

- [ ] **Step 4: Layout anpassen**

`app/templates/layout.twig:94` – vor `{% else %}` einen Zweig einfügen:

```twig
{% elseif path starts with '/antrag/' %}mx-auto w-full max-w-3xl px-4 py-6 sm:py-10
```

- [ ] **Step 5: Formular-Template neu schreiben**

`app/templates/public/membership_application.twig` (vollständig ersetzen):

```twig
{% extends 'layout.twig' %}

{% block content %}
{% set steps = [{'id': 'person', 'label': t('ui.1_persoenliche_angaben')|split('. ')|last}] %}
{% if invite.require_sepa %}{% set steps = steps|merge([{'id': 'sepa', 'label': t('applications.sepa.section_title')}]) %}{% endif %}
{% for d in declarations %}{% set steps = steps|merge([{'id': 'declaration-' ~ d.id, 'label': d.title, 'declaration': d}]) %}{% endfor %}
{% set steps = steps|merge([{'id': 'finish', 'label': t('ui.bestaetigung')|split('. ')|last}]) %}
{% set person_fields = ['salutation','title','first_name','last_name','email','phone','birth_date','address','house_number','zip','city','country'] %}
{% set sepa_fields = ['account_holder','iban','bic','sepa_mandate_consent'] %}

<div class="panel overflow-hidden">
    <header class="border-b border-line px-5 py-5 sm:px-8">
        <h1 class="h3 mb-1">{{ t('applications.public_title') }}</h1>
        <p class="mb-0 text-muted">{% if invite.title %}{{ t('ui.mitgliedsantrag_fuer') }} <strong class="text-ink">{{ invite.title }}</strong>{% else %}{{ t('applications.public_subtitle') }}{% endif %}</p>
        <div class="mt-4" aria-live="polite">
            <p class="mb-1 text-sm font-semibold" data-stepper-status>{{ t('applications.step_count', {'%current%': 1, '%total%': steps|length}) }}</p>
            <div class="h-1.5 overflow-hidden rounded-full bg-subtle"><div class="h-full bg-primary transition-all" style="width: {{ (100 / steps|length)|round }}%" data-stepper-bar></div></div>
        </div>
    </header>

    <form method="post" action="/antrag/{{ token }}" novalidate data-application-stepper class="px-5 py-6 sm:px-8">
        {{ csrf_field() }}
        <input type="hidden" name="document_fingerprint" value="{{ document_fingerprint }}">
        {% if errors.document_fingerprint %}<p class="alert alert-danger" role="alert">{{ t(errors.document_fingerprint) }}</p>{% endif %}
        {% if errors.declarations is defined %}<p class="alert alert-danger" role="alert">{{ t(errors.declarations) }}</p>{% endif %}
        <noscript><p class="alert alert-warning">{{ t('applications.javascript_required') }}</p></noscript>

        <div class="hidden" aria-hidden="true">
            <label for="antrag-website">{{ t('ui.website') }}</label>
            <input type="text" id="antrag-website" name="website" tabindex="-1" autocomplete="off">
        </div>

        {% for step in steps %}
        {% set n = loop.index %}
        {% set has_error = (step.id == 'person' and errors|keys|filter(k => k in person_fields)|length > 0)
            or (step.id == 'sepa' and errors|keys|filter(k => k in sepa_fields)|length > 0)
            or (step.declaration is defined and (errors['declaration_' ~ step.declaration.id] is defined or errors['signature_' ~ step.declaration.id] is defined))
            or (step.id == 'finish' and errors.signed_place is defined) %}
        <section data-step id="schritt-{{ n }}" aria-labelledby="schritt-{{ n }}-title"{% if has_error %} data-step-error{% endif %}>
            <h2 id="schritt-{{ n }}-title" class="h4 mb-4" tabindex="-1"><span class="text-muted">{{ n }}.</span> {{ step.label }}</h2>

            {% if step.id == 'person' %}
                {% include 'members/_name_fields.twig' with {id_prefix: 'antrag', values: input, errors: errors, large: false} %}
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="antrag-email" class="label required">{{ t('members.field.email') }}</label>
                        <input type="email" id="antrag-email" name="email" value="{{ input.email|default('') }}" class="input{% if errors.email %} is-invalid{% endif %}" required maxlength="254" autocomplete="email">
                        {% if errors.email %}<p class="field-error">{{ t(errors.email) }}</p>{% endif %}
                    </div>
                    <div>
                        <label for="antrag-phone" class="label">{{ t('members.field.phone') }}</label>
                        <input type="tel" id="antrag-phone" name="phone" value="{{ input.phone|default('') }}" class="input" maxlength="50" autocomplete="tel">
                    </div>
                    <div>
                        <label for="antrag-birth-date" class="label">{{ t('members.field.birth_date') }}</label>
                        <input type="date" id="antrag-birth-date" name="birth_date" value="{{ input.birth_date|default('') }}" class="input{% if errors.birth_date %} is-invalid{% endif %}" autocomplete="bday">
                        {% if errors.birth_date %}<p class="field-error">{{ t(errors.birth_date) }}</p>{% endif %}
                    </div>
                </div>
                <h3 class="h6 mt-6 mb-3">{{ t('ui.2_anschrift')|split('. ')|last }}</h3>
                <div class="grid gap-4 sm:grid-cols-6">
                    <div class="sm:col-span-4"><label for="antrag-address" class="label">{{ t('members.field.address') }}</label><input type="text" id="antrag-address" name="address" value="{{ input.address|default('') }}" class="input" maxlength="200" autocomplete="address-line1"></div>
                    <div class="sm:col-span-2"><label for="antrag-house-number" class="label">{{ t('members.field.house_number') }}</label><input type="text" id="antrag-house-number" name="house_number" value="{{ input.house_number|default('') }}" class="input" maxlength="20"></div>
                    <div class="sm:col-span-2"><label for="antrag-zip" class="label">{{ t('members.field.zip') }}</label><input type="text" id="antrag-zip" name="zip" value="{{ input.zip|default('') }}" class="input" maxlength="20" inputmode="numeric" autocomplete="postal-code"></div>
                    <div class="sm:col-span-4"><label for="antrag-city" class="label">{{ t('members.field.city') }}</label><input type="text" id="antrag-city" name="city" value="{{ input.city|default('') }}" class="input" maxlength="100" autocomplete="address-level2"></div>
                    <div class="sm:col-span-6"><label for="antrag-country" class="label">{{ t('members.field.country') }}</label><input type="text" id="antrag-country" name="country" value="{{ input.country|default('Deutschland') }}" class="input" maxlength="100" autocomplete="country-name"></div>
                </div>

            {% elseif step.id == 'sepa' %}
                <div class="grid gap-4 sm:grid-cols-6">
                    <div class="sm:col-span-6"><label for="antrag-account-holder" class="label required">{{ t('applications.sepa.account_holder') }}</label><input type="text" id="antrag-account-holder" name="account_holder" value="{{ input.account_holder|default('') }}" class="input{% if errors.account_holder %} is-invalid{% endif %}" required maxlength="200"></div>
                    <div class="sm:col-span-4"><label for="antrag-iban" class="label required">{{ t('applications.sepa.iban') }}</label><input type="text" id="antrag-iban" name="iban" value="{{ input.iban|default('') }}" class="input font-mono{% if errors.iban %} is-invalid{% endif %}" required maxlength="34"></div>
                    <div class="sm:col-span-2"><label for="antrag-bic" class="label">{{ t('applications.sepa.bic') }}</label><input type="text" id="antrag-bic" name="bic" value="{{ input.bic|default('') }}" class="input font-mono" maxlength="11"></div>
                </div>
                <div class="mt-4 rounded-xl border border-line bg-subtle p-4">
                    <p class="text-sm text-muted mb-3">{{ t('applications.sepa.mandate_text') }}</p>
                    <div class="check"><input class="check-input{% if errors.sepa_mandate_consent %} is-invalid{% endif %}" type="checkbox" id="sepa-consent" name="sepa_mandate_consent" value="1"{% if input.sepa_mandate_consent %} checked{% endif %} required><label class="check-label font-semibold" for="sepa-consent">{{ t('applications.sepa.mandate_consent') }}</label></div>
                </div>

            {% elseif step.declaration is defined %}
                {% include 'public/_declaration_step.twig' with {declaration: step.declaration, answer: answers[step.declaration.id]|default(''), signature: signatures[step.declaration.id]|default(''), errors: errors, preview: false} %}

            {% else %}
                <label for="antrag-notes" class="label">{{ t('members.field.notes') }}</label>
                <textarea id="antrag-notes" name="notes" rows="3" class="input mb-6" placeholder="{{ t('ui.moechtest_du_uns_noch_etwas_mitteilen') }}" maxlength="1000">{{ input.notes|default('') }}</textarea>
                {% if declarations is not empty %}
                <dl class="divide-y divide-line rounded-xl border border-line mb-6">
                    {% for d in declarations %}
                    <div class="flex flex-wrap items-center gap-3 px-4 py-3">
                        <dt class="min-w-0 flex-1 font-semibold">{{ d.title }}</dt>
                        <dd class="m-0 text-sm text-muted" data-summary-choice="{{ d.id }}">{% if d.kind == 'notice' %}{{ t('declarations.noticed') }}{% endif %}</dd>
                        {% if d.signature_required %}<img class="h-8 w-auto rounded bg-white" alt="{{ t('declarations.signature') }}" data-summary-signature="{{ d.id }}" hidden>{% endif %}
                        <a class="text-sm" href="#schritt-{{ (invite.require_sepa ? 2 : 1) + loop.index }}" data-step-goto="{{ (invite.require_sepa ? 1 : 0) + loop.index }}">{{ t('common.edit') }}</a>
                    </div>
                    {% endfor %}
                </dl>
                {% endif %}
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="antrag-place" class="label{% if declarations|filter(d => d.signature_required)|length > 0 %} required{% endif %}">{{ t('declarations.place') }}</label>
                        <input type="text" id="antrag-place" name="signed_place" value="{{ input.signed_place|default('') }}" class="input{% if errors.signed_place is defined %} is-invalid{% endif %}" maxlength="100" autocomplete="address-level2"{% if declarations|filter(d => d.signature_required)|length > 0 %} required{% endif %}>
                        {% if errors.signed_place is defined %}<p class="field-error">{{ t(errors.signed_place) }}</p>{% endif %}
                    </div>
                    <div><span class="label">{{ t('declarations.date') }}</span><p class="input bg-subtle mb-0">{{ today }}</p></div>
                </div>
            {% endif %}

            <div class="mt-8 flex flex-col-reverse gap-2 border-t border-line pt-4 sm:flex-row sm:justify-between">
                {% if not loop.first %}<button type="button" class="btn" data-step-prev>{{ t('common.back') }}</button>{% else %}<span></span>{% endif %}
                {% if loop.last %}
                <button type="submit" class="btn btn-primary btn-lg" data-stepper-submit disabled>{{ t('applications.submit') }}</button>
                {% else %}
                <button type="button" class="btn btn-primary" data-step-next>{{ t('applications.next') }}</button>
                {% endif %}
            </div>
        </section>
        {% endfor %}
    </form>
</div>
{% endblock %}
{% block scripts %}<script src="{{ asset('signature-pad.js') }}"></script><script src="{{ asset('application-stepper.js') }}"></script>{% endblock %}
```

Texte in `app/src/Text/de/declarations.php` ergänzen (vorher mit `grep -n "'common.edit'\|'applications.next'" app/src/Text/de/*.php` prüfen, ob sie existieren – nur fehlende anlegen, keine Duplikate):

```php
    'applications.step_count' => 'Schritt %current% von %total%',
    'applications.next' => 'Weiter',
    'applications.javascript_required' => 'Zum Unterschreiben wird JavaScript benötigt. Bitte aktiviere JavaScript in deinem Browser.',
    'js.step_count' => 'Schritt %current% von %total%',
    'js.declaration_confirmed' => 'Bestätigt',
```

Prüfen, wie `t()` Parameter ersetzt (`grep -n "function t\b\|'t'" app/src/Http/*.php app/src/Text/*.php`); verwendet es eine andere Platzhalter-Syntax, `%current%`/`%total%` daran anpassen.

Das Label „Geburtsdatum (JJJJ-MM-TT)“ kommt aus `members.field.birth_date`, wenn dort das Format steht: `grep -n "JJJJ-MM-TT" app/src/Text/de/*.php` und den Text auf „Geburtsdatum“ ändern; das Format zeigt `input-masks.js` bereits als Hilfetext an.

- [ ] **Step 6: PHP-Tests laufen lassen – müssen bestehen**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php sh -c "cd app && vendor/bin/phpunit tests/Web/MembershipApplicationPagesTest.php tests/Web/ResponsiveMarkupTest.php tests/Text"`
Expected: `OK`

- [ ] **Step 7: Failing JS-Test für den Stepper schreiben**

`tests/application-stepper.test.cjs`:

```js
const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {JSDOM} = require('jsdom');

const html = `<p data-stepper-status></p><div data-stepper-bar></div>
<form novalidate data-application-stepper>
 <section data-step id="schritt-1"><h2 tabindex="-1">Person</h2><input name="last_name" required><button type="button" data-step-next>Weiter</button></section>
 <section data-step id="schritt-2"><h2 tabindex="-1">Antrag</h2><input type="checkbox" name="declaration[1]" value="1" required>
  <div data-signature-pad data-signature-required><input type="hidden" name="signature[1]" value="" data-signature-value></div>
  <button type="button" data-step-prev>Zurück</button><button type="button" data-step-next>Weiter</button></section>
 <section data-step id="schritt-3"><h2 tabindex="-1">Foto</h2>
  <label><input type="radio" name="declaration[2]" value="yes" required><span>Ich willige ein</span></label>
  <label><input type="radio" name="declaration[2]" value="no" required><span>Ich willige nicht ein</span></label>
  <button type="button" data-step-prev>Zurück</button><button type="button" data-step-next>Weiter</button></section>
 <section data-step id="schritt-4"><h2 tabindex="-1">Abschluss</h2>
  <dd data-summary-choice="1"></dd><img data-summary-signature="1" hidden><dd data-summary-choice="2"></dd>
  <a href="#schritt-3" data-step-goto="2">Ändern</a>
  <button type="button" data-step-prev>Zurück</button><button type="submit" data-stepper-submit disabled>Absenden</button></section>
</form>`;

function setup(extra = '') {
    const dom = new JSDOM(html.replace('id="schritt-3"', 'id="schritt-3"' + extra), {runScripts: 'outside-only', url: 'https://verein.example.org/antrag/x'});
    const w = dom.window;
    w.HTMLElement.prototype.scrollIntoView = () => {};
    require('./browser-texts.cjs')(w);
    w.eval(readFileSync(require.resolve('../public/assets/application-stepper.js'), 'utf8'));
    return {w, d: w.document, visible: () => [...w.document.querySelectorAll('[data-step]')].filter(s => !s.hidden).map(s => s.id)};
}

test('only the first step is visible and submit becomes available', () => {
    const {w, d, visible} = setup();
    try {
        assert.deepEqual(visible(), ['schritt-1']);
        assert.equal(d.querySelector('[data-stepper-submit]').disabled, false);
        assert.match(d.querySelector('[data-stepper-status]').textContent, /1 von 4/);
    } finally { w.close(); }
});

test('next blocks incomplete steps including missing signature', () => {
    const {w, d, visible} = setup();
    try {
        d.querySelector('#schritt-1 [data-step-next]').click();
        assert.deepEqual(visible(), ['schritt-1']);
        d.querySelector('[name="last_name"]').value = 'Muster';
        d.querySelector('#schritt-1 [data-step-next]').click();
        assert.deepEqual(visible(), ['schritt-2']);
        assert.equal(d.activeElement, d.querySelector('#schritt-2 h2'));
        assert.equal(w.location.hash, '#schritt-2');
        d.querySelector('[name="declaration[1]"]').checked = true;
        d.querySelector('#schritt-2 [data-step-next]').click();
        assert.deepEqual(visible(), ['schritt-2']);
        assert.ok(d.querySelector('[data-signature-pad]').classList.contains('is-invalid'));
        d.querySelector('[data-signature-value]').value = 'data:image/png;base64,AAAA';
        d.querySelector('#schritt-2 [data-step-next]').click();
        assert.deepEqual(visible(), ['schritt-3']);
        d.querySelector('#schritt-3 [data-step-prev]').click();
        assert.deepEqual(visible(), ['schritt-2']);
    } finally { w.close(); }
});

test('summary shows choices and signature, change link jumps back', () => {
    const {w, d, visible} = setup();
    try {
        d.querySelector('[name="last_name"]').value = 'Muster';
        d.querySelector('[name="declaration[1]"]').checked = true;
        d.querySelector('[data-signature-value]').value = 'data:image/png;base64,AAAA';
        d.querySelector('[name="declaration[2]"][value="no"]').checked = true;
        for (const id of ['#schritt-1', '#schritt-2', '#schritt-3']) { d.querySelector(id + ' [data-step-next]').click(); }
        assert.deepEqual(visible(), ['schritt-4']);
        assert.equal(d.querySelector('[data-summary-choice="1"]').textContent, 'Bestätigt');
        assert.equal(d.querySelector('[data-summary-choice="2"]').textContent, 'Ich willige nicht ein');
        const img = d.querySelector('[data-summary-signature="1"]');
        assert.equal(img.hidden, false);
        assert.equal(img.getAttribute('src'), 'data:image/png;base64,AAAA');
        d.querySelector('[data-step-goto]').click();
        assert.deepEqual(visible(), ['schritt-3']);
    } finally { w.close(); }
});

test('server error opens the first step with an error', () => {
    const {w, visible} = setup(' data-step-error');
    try { assert.deepEqual(visible(), ['schritt-3']); } finally { w.close(); }
});

test('submit with an incomplete step jumps there instead of sending', () => {
    const {w, d, visible} = setup();
    try {
        let sent = 0; d.querySelector('form').addEventListener('submit', e => { if (!e.defaultPrevented) { sent++; } e.preventDefault(); });
        d.querySelector('form').dispatchEvent(new w.Event('submit', {bubbles: true, cancelable: true}));
        assert.equal(sent, 0);
        assert.deepEqual(visible(), ['schritt-1']);
    } finally { w.close(); }
});
```

- [ ] **Step 8: Test laufen lassen – muss fehlschlagen**

Run: `node --test tests/application-stepper.test.cjs`
Expected: FAIL, `ENOENT … application-stepper.js`.

- [ ] **Step 9: Stepper implementieren**

`public/assets/application-stepper.js`:

```js
// Mitgliedsantrag als Stepper: ein Formular, Schritte werden nur ein- und ausgeblendet.
(function () {
    'use strict';
    var texts = window.VkTexts || {};
    function text(key, fallback) { return texts[key] || fallback; }

    function Stepper(form) {
        this.form = form;
        this.steps = Array.prototype.slice.call(form.querySelectorAll('[data-step]'));
        this.status = document.querySelector('[data-stepper-status]');
        this.bar = document.querySelector('[data-stepper-bar]');
        var self = this;
        form.addEventListener('click', function (e) {
            var t = e.target.closest('[data-step-next],[data-step-prev],[data-step-goto]');
            if (!t) { return; }
            e.preventDefault();
            if (t.hasAttribute('data-step-next')) { if (self.valid(self.index)) { self.show(self.index + 1, true); } }
            else if (t.hasAttribute('data-step-prev')) { self.show(self.index - 1, true); }
            else { self.show(parseInt(t.getAttribute('data-step-goto'), 10), true); }
        });
        form.addEventListener('submit', function (e) {
            for (var i = 0; i < self.steps.length; i++) {
                if (!self.valid(i)) { e.preventDefault(); self.show(i, true); self.valid(i); return; }
            }
        });
        form.addEventListener('signature:change', function (e) { e.target.classList.remove('is-invalid'); });
        window.addEventListener('popstate', function () { self.show(self.fromHash(), false); });
        var submit = form.querySelector('[data-stepper-submit]');
        if (submit) { submit.disabled = false; }
        var error = this.steps.findIndex(function (s) { return s.hasAttribute('data-step-error'); });
        this.index = -1;
        this.show(error >= 0 ? error : this.fromHash(), false, error < 0 && this.fromHash() === 0);
    }

    Stepper.prototype.fromHash = function () {
        var m = /^#schritt-(\d+)$/.exec(window.location.hash);
        var i = m ? parseInt(m[1], 10) - 1 : 0;
        return i >= 0 && i < this.steps.length ? i : 0;
    };

    Stepper.prototype.show = function (index, push, silent) {
        if (index < 0 || index >= this.steps.length) { return; }
        this.index = index;
        this.steps.forEach(function (s, i) { s.hidden = i !== index; });
        var n = index + 1, total = this.steps.length;
        if (this.status) { this.status.textContent = text('js.step_count', 'Schritt %current% von %total%').replace('%current%', n).replace('%total%', total); }
        if (this.bar) { this.bar.style.width = Math.round(n / total * 100) + '%'; }
        if (index === this.steps.length - 1) { this.summary(); }
        if (push && window.location.hash !== '#schritt-' + n) { window.history.pushState(null, '', '#schritt-' + n); }
        if (!silent) {
            var heading = this.steps[index].querySelector('h2');
            if (heading) { heading.focus(); if (heading.scrollIntoView) { heading.scrollIntoView({block: 'start'}); } }
        }
    };

    Stepper.prototype.valid = function (index) {
        var step = this.steps[index], ok = true, first = null;
        step.querySelectorAll('input, select, textarea').forEach(function (el) {
            if (el.type === 'hidden' || el.disabled || !el.willValidate) { return; }
            if (!el.checkValidity()) { ok = false; el.classList.add('is-invalid'); first = first || el; }
            else { el.classList.remove('is-invalid'); }
        });
        step.querySelectorAll('[data-signature-pad][data-signature-required]').forEach(function (pad) {
            var value = pad.querySelector('[data-signature-value]');
            if (!value || value.value === '') { ok = false; pad.classList.add('is-invalid'); first = first || pad.querySelector('canvas') || pad; }
        });
        if (first) {
            if (first.reportValidity) { first.reportValidity(); }
            if (first.focus) { first.focus(); }
        }
        return ok;
    };

    Stepper.prototype.summary = function () {
        var form = this.form;
        form.querySelectorAll('[data-summary-choice]').forEach(function (el) {
            var id = el.getAttribute('data-summary-choice');
            var checked = form.querySelector('[name="declaration[' + id + ']"]:checked');
            if (!checked) { return; }
            el.textContent = checked.type === 'checkbox'
                ? text('js.declaration_confirmed', 'Bestätigt')
                : (checked.closest('label') ? checked.closest('label').textContent.trim() : checked.value);
        });
        form.querySelectorAll('[data-summary-signature]').forEach(function (img) {
            var value = form.querySelector('[name="signature[' + img.getAttribute('data-summary-signature') + ']"]');
            if (value && value.value) { img.setAttribute('src', value.value); img.hidden = false; } else { img.hidden = true; }
        });
    };

    function init() {
        document.querySelectorAll('form[data-application-stepper]').forEach(function (form) {
            if (!form.vkStepper) { form.vkStepper = new Stepper(form); }
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
```

Wie in Task 7: Falls `tests/browser-texts.cjs` die Texte unter einem anderen globalen Namen bereitstellt, `window.VkTexts` an beiden Stellen anpassen.

- [ ] **Step 10: JS-Tests laufen lassen – müssen bestehen**

Run: `npm test --prefix tests`
Expected: alle Tests `pass`, `fail 0`

- [ ] **Step 11: CSS bauen und Screenshots prüfen**

```bash
node scripts/build-css.cjs
docker compose -f docker-compose.dev.yml exec -T db psql -U vk -d vereinskiste_dev -c "INSERT INTO membership_application_invites (token,title,require_sepa,created_by,expires_at) VALUES ('stepper$(date +%s)000000000000000000000000000000000','Wintersemester',true,1,now()+interval '30 days') RETURNING token"
```

Dev-Server wie im README starten (`docker compose -f docker-compose.dev.yml run -d --name vk-web -p 8080:8080 php php -S 0.0.0.0:8080 -t public`) und mit Chrome headless bei 400 px und 1300 px Breite Screenshots von `/antrag/<token>` und `/antrag/<token>#schritt-3` erzeugen. Ansehen: kein horizontales Scrollen, ein Logo, Fortschrittsanzeige, Unterschriftsfeld in voller Breite.

- [ ] **Step 12: Commit**

```bash
git add app/templates app/src public/assets/application-stepper.js tests/application-stepper.test.cjs app/styles/app.css app/tests
git commit -m "Mitgliedsantrag als Stepper mit Erklaerungen und Unterschriften

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Antragsansicht, Unterschriftsbild und PDF

**Files:**
- Create: `app/src/Members/ApplicationPdf.php`
- Modify: `app/src/Web/ApplicationDecisionController.php` (`page()`, neue Methoden `signature()`, `pdf()`)
- Modify: `app/src/Http/Kernel.php` (zwei Routen)
- Modify: `app/templates/members/decision.twig`
- Test: `app/tests/Web/ApplicationSignaturePagesTest.php`

**Interfaces:**
- Consumes: `ApplicationSignatures::forApplication()`, `::png()`, `ApplicationRepository::findApplication()`.
- Produces: `ApplicationPdf::render(array $application, list<array> $signatures, array<int,string> $pngs, string $club): string`; Routen `GET /members/applications/{id}/signatures/{signature}.png`, `GET /members/applications/{id}/pdf` (beide `members.write`).

- [ ] **Step 1: Failing test schreiben**

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Members\ApplicationRepository;
use Vereinskiste\Members\ApplicationSignatures;
use Vereinskiste\Tests\Support\ApplicationInput;
use Vereinskiste\Tests\TestCase;

final class ApplicationSignaturePagesTest extends TestCase
{
    use WebHarness;

    private PDO $pdo;
    private int $application;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->store = new ArraySessionStore();
        $this->app = Kernel::create(['pdo' => $this->pdo, 'session_store' => $this->store, 'require_https' => false, 'security_dir' => $this->securityDir()]);
        $this->webUser('admin@example.org', ['admin']);
        $repo = new ApplicationRepository($this->pdo);
        $invite = $repo->createInvite(1, 'Test', 7);
        $this->application = $repo->submit($invite['token'], ['first_name' => 'Erika', 'last_name' => 'Muster', 'email' => 'erika@example.org']
            + ApplicationInput::declarations($this->pdo, 'yes'));
    }

    public function test_decision_page_lists_declarations_with_signatures(): void
    {
        $this->loginAs(['secretary']);
        $html = (string) $this->get("/members/applications/{$this->application}/decision")->getBody();
        $this->assertStringContainsString('Antrag auf Mitgliedschaft', $html);
        $this->assertStringContainsString('Ich willige ein', $html);
        $this->assertStringContainsString('Erlangen', $html);
        $first = (new ApplicationSignatures($this->pdo))->forApplication($this->application)[0]['id'];
        $this->assertStringContainsString("/members/applications/{$this->application}/signatures/$first.png", $html);
        $this->assertStringContainsString("/members/applications/{$this->application}/pdf", $html);
    }

    public function test_signature_image_and_pdf_need_permission(): void
    {
        $first = (new ApplicationSignatures($this->pdo))->forApplication($this->application)[0]['id'];
        $this->loginAs(['secretary']);
        $png = $this->get("/members/applications/{$this->application}/signatures/$first.png");
        $this->assertSame('image/png', $png->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $png->getHeaderLine('Cache-Control'));
        $this->assertSame("\x89PNG", substr((string) $png->getBody(), 0, 4));
        $this->assertSame(404, $this->get("/members/applications/{$this->application}/signatures/999.png")->getStatusCode());

        $pdf = $this->get("/members/applications/{$this->application}/pdf");
        $this->assertSame('application/pdf', $pdf->getHeaderLine('Content-Type'));
        $body = (string) $pdf->getBody();
        $this->assertStringStartsWith('%PDF', $body);
        $this->assertStringContainsString('/Subtype /Image', $body);
        $this->assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM audit_log WHERE action = 'application_pdf_downloaded'")->fetchColumn());

        $this->pdo->exec("UPDATE user_roles SET role = 'treasurer'");
        $this->assertSame(403, $this->get("/members/applications/{$this->application}/pdf")->getStatusCode());
    }
}
```

- [ ] **Step 2: Test laufen lassen – muss fehlschlagen**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php sh -c "cd app && vendor/bin/phpunit tests/Web/ApplicationSignaturePagesTest.php"`
Expected: FAIL (Inhalte bzw. Routen fehlen).

- [ ] **Step 3: PDF-Baustein schreiben**

`app/src/Members/ApplicationPdf.php`:

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Members;

use Dompdf\Dompdf;
use Dompdf\Options;
use Vereinskiste\ClubDocuments\Content;

/** Mitgliedsantrag im Stil des Papierformulars, erzeugt aus den gespeicherten Snapshots. */
final class ApplicationPdf
{
    /**
     * @param array<string,mixed> $application aus ApplicationRepository::findApplication()
     * @param list<array<string,mixed>> $signatures aus ApplicationSignatures::forApplication()
     * @param array<int,string> $pngs Unterschrift-PNG je Signatur-ID
     */
    public static function render(array $application, array $signatures, array $pngs, string $club): string
    {
        $e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $data = $application['data'];
        $person = '';
        foreach ([
            'Name' => trim(($data['last_name'] ?? '') . ''), 'Vorname' => $data['first_name'] ?? '',
            'Anschrift' => trim(trim(($data['address'] ?? '') . ' ' . ($data['house_number'] ?? '')) . ', ' . trim(($data['zip'] ?? '') . ' ' . ($data['city'] ?? '')), ', '),
            'Geburtsdatum' => ($data['birth_date'] ?? '') !== '' ? (new \DateTimeImmutable($data['birth_date']))->format('d.m.Y') : '',
            'Telefonnummer' => $data['phone'] ?? '', 'E-Mail-Adresse' => $application['email'],
        ] as $label => $value) {
            $person .= '<tr><th>' . $e($label) . ':</th><td>' . $e($value) . '</td></tr>';
        }
        if (($data['iban'] ?? '') !== '') {
            $person .= '<tr><th>Kontoinhaber:</th><td>' . $e($data['account_holder'] ?? '') . '</td></tr><tr><th>IBAN:</th><td>' . $e($data['iban']) . '</td></tr>';
        }
        $sections = '';
        foreach ($signatures as $i => $s) {
            $snap = $s['snapshot'];
            $choice = match ($s['kind']) {
                'confirmation' => '<p>☒ Bestätigt</p>',
                'consent' => '<p>' . ($s['granted'] ? '☒' : '☐') . ' Ich willige ein<br>' . ($s['granted'] ? '☐' : '☒') . ' Ich willige nicht ein</p>',
                default => '',
            };
            $signature = isset($pngs[$s['id']])
                ? '<img class="sig" src="data:image/png;base64,' . base64_encode($pngs[$s['id']]) . '">'
                : '';
            $foot = $s['has_signature']
                ? '<table class="sign"><tr><td>Ort, Datum: ' . $e($s['signed_place']) . ', ' . $e((new \DateTimeImmutable($s['signed_at']))->format('d.m.Y')) . '</td><td>Unterschrift: ' . $signature . '</td></tr></table>'
                : '';
            $sections .= '<h2>' . ($i + 2) . '. ' . $e($snap['title'] ?? '') . '</h2>' . Content::clean((string) ($snap['content'] ?? '')) . $choice . $foot
                . '<p class="hash">Version ' . $e($s['version']) . ' · SHA-256 ' . $e($s['snapshot_hash']) . '</p>';
        }
        $html = '<!doctype html><html lang="de"><meta charset="utf-8"><style>@page{margin:20mm}body{font-family:DejaVu Sans;font-size:10pt;line-height:1.4}'
            . 'h1{font-size:16pt;margin:0}h2{font-size:12pt;margin-top:18pt}table{width:100%;border-collapse:collapse}th{text-align:left;width:32%;font-weight:normal;padding:3pt 0}'
            . 'td{padding:3pt 0;border-bottom:0.5pt solid #999}.sign td{border:0;vertical-align:bottom;width:50%}.sig{height:40pt}.hash{color:#666;font-size:7pt}</style><body>'
            . '<h1>Mitgliedsantrag</h1><p>' . $e($club) . '</p><h2>1. Angaben zur Person</h2><table>' . $person . '</table>' . $sections
            . '<p class="hash">Antrag #' . $e($application['id']) . ' · eingegangen ' . $e((new \DateTimeImmutable($application['created_at']))->format('d.m.Y H:i')) . '</p></body></html>';
        $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans',
            'chroot' => dirname(__DIR__, 2) . '/vendor/dompdf', 'allowedProtocols' => ['data://' => ['rules' => []]]]);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        return (string) $pdf->output();
    }
}
```

Prüfen, welche Schlüssel `findApplication()` liefert (`id`, `email`, `data`, `created_at`); heißen sie anders, die Zugriffe anpassen.

- [ ] **Step 4: Controller, Routen und Template**

In `ApplicationDecisionController::page()` im Render-Array ergänzen:

```php
'signatures'=>(new \Vereinskiste\Members\ApplicationSignatures(($this->pdo)()))->forApplication($id),
```

Neue Methoden:

```php
    public function signature(Request $req,Response $res,array $args):Response
    {
        $png=(new \Vereinskiste\Members\ApplicationSignatures(($this->pdo)()))->png((int)$args['id'],(int)$args['signature']);
        if($png===null) {return $this->notFound($req);}
        $res->getBody()->write($png);
        return $res->withHeader('Content-Type','image/png')->withHeader('Cache-Control','no-store');
    }
    public function pdf(Request $req,Response $res,array $args):Response
    {
        $pdo=($this->pdo)();$id=(int)$args['id'];
        $app=(new ApplicationRepository($pdo))->findApplication($id);
        if(!$app) {return $this->notFound($req);}
        $signatures=new \Vereinskiste\Members\ApplicationSignatures($pdo);$rows=$signatures->forApplication($id);$pngs=[];
        foreach($rows as $row) {if($row['has_signature']) {$pngs[$row['id']]=(string)$signatures->png($id,$row['id']);}}
        $club=(string)((new \Vereinskiste\Club\Profile($pdo))->get()['data']['name']??'');
        $body=\Vereinskiste\Members\ApplicationPdf::render($app,$rows,$pngs,$club);
        (new \Vereinskiste\Audit\AuditLog($pdo))->record('application_pdf_downloaded',$this->userId($req),['application_id'=>$id]);
        $res->getBody()->write($body);
        return $res->withHeader('Content-Type','application/pdf')->withHeader('Content-Disposition','attachment; filename="mitgliedsantrag-'.$id.'.pdf"')->withHeader('Cache-Control','no-store');
    }
```

In `app/src/Http/Kernel.php` nach der `decision`-POST-Route:

```php
        $app->get('/members/applications/{id:[0-9]+}/signatures/{signature:[0-9]+}.png', [$decisions, 'signature'])->add(Auth::requires('members.write'));
        $app->get('/members/applications/{id:[0-9]+}/pdf', [$decisions, 'pdf'])->add(Auth::requires('members.write'));
```

In `app/templates/members/decision.twig` nach `<p>{{ application.email }}</p>`:

```twig
<p><a class="btn" href="/members/applications/{{ application.id }}/pdf">{{ t('applications.pdf') }}</a></p>
{% if signatures is not empty %}
<section class="panel panel-body mb-4" aria-labelledby="declarations-heading">
    <h2 id="declarations-heading" class="panel-title">{{ t('declarations.title') }}</h2>
    <ul class="divide-y divide-line">
    {% for s in signatures %}
        <li class="py-3">
            <div class="flex flex-wrap items-center gap-3">
                <strong class="min-w-0 flex-1">{{ s.snapshot.title|default('') }}</strong>
                <span class="text-sm text-muted">{{ t('declarations.field.version') }} {{ s.version }}</span>
                <span>{% if s.kind == 'consent' %}{{ t(s.granted ? 'declarations.consent_yes' : 'declarations.consent_no') }}{% elseif s.kind == 'confirmation' %}{{ t('declarations.confirmed') }}{% else %}{{ t('declarations.noticed') }}{% endif %}</span>
            </div>
            {% if s.has_signature %}
            <div class="mt-2 flex flex-wrap items-end gap-4">
                <img class="h-16 w-auto rounded border border-line bg-white" src="/members/applications/{{ application.id }}/signatures/{{ s.id }}.png" alt="{{ t('declarations.signature') }}">
                <span class="text-sm text-muted">{{ t('declarations.place_date') }}: {{ s.signed_place }}, {{ s.signed_at|date('d.m.Y') }}</span>
            </div>
            {% endif %}
            <details class="mt-2"><summary class="text-sm">{{ t('privacy.snapshot') }}</summary><div class="rich-text text-sm">{{ s.snapshot.content|default('')|note }}</div><code class="text-xs">{{ s.snapshot_hash }}</code></details>
        </li>
    {% endfor %}
    </ul>
</section>
{% endif %}
```

Text ergänzen in `declarations.php` (falls nicht vorhanden): `'applications.pdf' => 'Antrag als PDF herunterladen',`.

- [ ] **Step 5: Test laufen lassen – muss bestehen**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php sh -c "cd app && vendor/bin/phpunit tests/Web/ApplicationSignaturePagesTest.php tests/Text"`
Expected: `OK`

- [ ] **Step 6: Commit**

```bash
git add app/src app/templates/members/decision.twig app/tests/Web/ApplicationSignaturePagesTest.php
git commit -m "Antragsansicht mit Unterschriften und PDF im Stil des Papierformulars

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Widerruf von Einwilligungen aus Erklärungen

**Files:**
- Modify: `app/src/Privacy/Consents.php` (`record()`, `KINDS`)
- Modify: `app/src/Web/PrivacyController.php` (`memberPage()`)
- Modify: `app/templates/privacy/member.twig`
- Test: `app/tests/Privacy/DeclarationConsentTest.php`

**Interfaces:**
- Consumes: `Declarations::all()`, Spalte `member_consents.declaration_id`.
- Produces: `Consents::record()` akzeptiert `kind = 'declaration'` mit Pflichtfeld `declaration_id` (nur Erklärungen der Art `consent`); `Consents::list()` liefert zusätzlich `declaration_id`.

- [ ] **Step 1: Failing test schreiben**

```php
<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Privacy;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\ApplicationRepository;
use Vereinskiste\Members\Declarations;
use Vereinskiste\Privacy\Consents;
use Vereinskiste\Tests\Support\ApplicationInput;
use Vereinskiste\Tests\TestCase;

final class DeclarationConsentTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->pdo->exec("INSERT INTO users (email, name, password_hash) VALUES ('v@example.org', 'V', 'x')");
    }

    public function test_staff_withdraws_photo_consent_from_application(): void
    {
        $repo = new ApplicationRepository($this->pdo);
        $invite = $repo->createInvite(1, 'Test', 7);
        $member = $repo->approve($repo->submit($invite['token'], ['first_name' => 'E', 'last_name' => 'M', 'email' => 'e@example.org'] + ApplicationInput::declarations($this->pdo, 'yes')), 1);
        $photo = (new Declarations($this->pdo))->all()[3];

        (new Consents($this->pdo))->record($member, ['kind' => 'declaration', 'declaration_id' => (string) $photo['id'], 'granted' => '0', 'reason' => 'Widerruf per E-Mail'], 1);
        $latest = (new Consents($this->pdo))->list($member)[0];
        $this->assertSame('declaration', $latest['kind']);
        $this->assertSame($photo['id'], (int) $latest['declaration_id']);
        $this->assertFalse((bool) $latest['granted']);
        $this->assertSame($photo['title'], $latest['snapshot']['title']);

        $this->expectException(ValidationError::class);
        $confirmation = (new Declarations($this->pdo))->all()[0];
        (new Consents($this->pdo))->record($member, ['kind' => 'declaration', 'declaration_id' => (string) $confirmation['id'], 'granted' => '0', 'reason' => 'x'], 1);
    }
}
```

- [ ] **Step 2: Test laufen lassen – muss fehlschlagen**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php sh -c "cd app && vendor/bin/phpunit tests/Privacy/DeclarationConsentTest.php"`
Expected: FAIL, `ValidationError` bei `kind = declaration`.

- [ ] **Step 3: Implementieren**

In `Consents`: `KINDS` um `'declaration'` erweitern. In `record()` nach der vorhandenen Kind-Prüfung und vor `Transaction::run` einfügen:

```php
        $declaration=null;
        if($data['kind']==='declaration') {
            $declaration=(new \Vereinskiste\Members\Declarations($this->pdo))->find((int)($input['declaration_id']??0));
            if($declaration===null||$declaration['kind']!=='consent') { throw new ValidationError(['declaration_id'=>'foundation.invalid']); }
        }
```

und innerhalb der Transaktion den Snapshot so bilden:

```php
            $snapshot=$declaration===null?$this->snapshot($data['kind']):['title'=>$declaration['title'],'content'=>$declaration['content'],'kind'=>'consent','version'=>$declaration['version']];
            if($declaration===null) {$snapshot['version']=$data['document_version'];}
            $this->append($member,null,$data['kind'],$input['granted']==='1',$snapshot,'staff',$actor,$data['reason'],$declaration['id']??null);
```

(ersetzt die bisherigen zwei Zeilen `$snapshot=…; $snapshot['version']=…; $this->append(…)`).

In `PrivacyController::memberPage()` im Render-Array ergänzen:

```php
'declarations'=>array_values(array_filter((new \Vereinskiste\Members\Declarations(($this->pdo)()))->all(),static fn(array $d):bool=>$d['kind']==='consent')),
```

In `app/templates/privacy/member.twig` das Kind-Select (Zeile 10) ersetzen durch:

```twig
<div class="col-span-12 md:col-span-3"><label class="label" for="consent-kind">{{ t('privacy.field.kind') }}</label><select id="consent-kind" name="kind" class="input">{% for kind in kinds|filter(k => k != 'declaration') %}<option value="{{ kind }}">{{ t('privacy.consent.' ~ kind) }}</option>{% endfor %}{% if declarations is not empty %}<option value="declaration">{{ t('privacy.consent.declaration') }}</option>{% endif %}</select>
{% if declarations is not empty %}<label class="label mt-2" for="consent-declaration">{{ t('declarations.title') }}</label><select id="consent-declaration" name="declaration_id" class="input">{% for d in declarations %}<option value="{{ d.id }}">{{ d.title }}</option>{% endfor %}</select>{% endif %}</div>
```

und in der Verlaufstabelle (Zeile 18) `{{ t('privacy.consent.' ~ consent.kind) }}` ersetzen durch `{{ consent.kind == 'declaration' ? consent.snapshot.title|default(t('privacy.consent.declaration')) : t('privacy.consent.' ~ consent.kind) }}`.

Text ergänzen: `'privacy.consent.declaration' => 'Antragserklärung',`.

- [ ] **Step 4: Tests laufen lassen – müssen bestehen**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php sh -c "cd app && vendor/bin/phpunit tests/Privacy tests/Text"`
Expected: `OK`

- [ ] **Step 5: Commit**

```bash
git add app/src/Privacy/Consents.php app/src/Web/PrivacyController.php app/templates/privacy/member.twig app/src/Text/de/declarations.php app/tests/Privacy/DeclarationConsentTest.php
git commit -m "Einwilligungen aus Antragserklaerungen widerrufen

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Gesamtprüfung und Spec-Abgleich

**Files:**
- Modify: `docs/superpowers/specs/2026-11-03-mitgliedsantrag-erklaerungen-design.md` (Abweichungen nachtragen)

- [ ] **Step 1: Alle Tests**

```bash
npm test --prefix tests
docker compose -f docker-compose.dev.yml run --rm -T php sh -c "cd app && vendor/bin/phpunit"
```

Expected: beide ohne Fehler.

- [ ] **Step 2: Release-Prüfung lokal**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php sh -c "sh scripts/build-release.sh ci"`
Expected: Exit 0 (prüft u. a., dass neue Assets im Paket landen). Falls `verify-release.php` eine Liste erwarteter Assets führt, `signature-pad.js` und `application-stepper.js` dort ergänzen.

- [ ] **Step 3: Manuell im Browser durchspielen**

Mit dem Dev-Server: Antrag mit SEPA einmal komplett ausfüllen (Maus), einmal bei 400 px Breite; danach in `/members/applications/<id>/decision` Unterschriften prüfen und das PDF öffnen. In `/settings/declarations` eine Erklärung ändern und prüfen, dass ein offenes Formular danach den Fingerprint-Hinweis zeigt.

- [ ] **Step 4: Spec abgleichen**

In der Spec nachtragen:
- Einwilligungen und Bestätigungen werden beim Absenden in `member_consents` geschrieben (nicht erst bei der Genehmigung); die Genehmigung überträgt sie wie bisher per `UPDATE member_consents SET member_id`.
- Links aus dem Vereinsprofil werden nicht automatisch in die Standardtexte übernommen; der Vorstand fügt sie im Editor ein.
- Nach einem Fingerprint-Hinweis bleiben alle gezeichneten Unterschriften im Formular erhalten (nicht nur die unveränderter Erklärungen); die Person sieht den geänderten Text im jeweiligen Schritt erneut, bevor sie absendet.
- Straße und Hausnummer sind überall getrennte Felder (Antrag, Mitglieder, Finanzkontakte, Vereinsprofil); Migration 0023 teilt eindeutige Altdaten auf.

- [ ] **Step 5: Commit**

```bash
git add docs/superpowers/specs/2026-11-03-mitgliedsantrag-erklaerungen-design.md
git commit -m "Spec an Umsetzung angeglichen

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
