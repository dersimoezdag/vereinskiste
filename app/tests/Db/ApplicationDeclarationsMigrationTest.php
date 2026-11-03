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
