<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Ledger;

use PDO;
use Vereinskiste\Audit\AuditLog;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\AccountRepository;
use Vereinskiste\Ledger\CategoryRepository;
use Vereinskiste\Tests\TestCase;

/** Konten und Kategorien: Anlegen, Umbenennen, (De-)Aktivieren schreibt atomar einen Protokolleintrag. */
final class MasterDataAuditTest extends TestCase
{
    private PDO $pdo;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->userId = (int) $this->pdo->query(
            "INSERT INTO users (email, name, password_hash) VALUES ('v@example.org', 'Vorstand', 'x') RETURNING id"
        )->fetchColumn();
    }

    /** @return list<array{action:string,user_id:int,detail:array<string,mixed>}> */
    private function entries(): array
    {
        $rows = $this->pdo->query('SELECT action, user_id, detail::text AS detail FROM audit_log ORDER BY id')->fetchAll();
        return array_map(static function (array $r): array {
            $detail = json_decode($r['detail'], true);
            ksort($detail); // jsonb sortiert Schluessel selbst
            return ['action' => $r['action'], 'user_id' => (int) $r['user_id'], 'detail' => $detail];
        }, $rows);
    }

    public function test_account_lifecycle_is_audited_with_actor_and_detail(): void
    {
        $repo = new AccountRepository($this->pdo);
        $id = $repo->create('Barkasse', 'cash', '0', '2026-01-01', $this->userId);
        $repo->rename($id, 'Handkasse', $this->userId);
        $repo->toggleActive($id, $this->userId);
        $repo->toggleActive($id, $this->userId);

        $this->assertSame([
            ['action' => 'account_created', 'user_id' => $this->userId, 'detail' => ['id' => $id, 'name' => 'Barkasse']],
            ['action' => 'account_renamed', 'user_id' => $this->userId, 'detail' => ['from' => 'Barkasse', 'id' => $id, 'to' => 'Handkasse']],
            ['action' => 'account_deactivated', 'user_id' => $this->userId, 'detail' => ['id' => $id, 'name' => 'Handkasse']],
            ['action' => 'account_activated', 'user_id' => $this->userId, 'detail' => ['id' => $id, 'name' => 'Handkasse']],
        ], $this->entries());
    }

    public function test_category_lifecycle_is_audited_with_actor_and_detail(): void
    {
        $repo = new CategoryRepository($this->pdo);
        $id = $repo->create('Porto', $this->userId);
        $repo->rename($id, 'Portokosten', $this->userId);
        $repo->toggleActive($id, $this->userId);
        $repo->toggleActive($id, $this->userId);

        $this->assertSame([
            ['action' => 'category_created', 'user_id' => $this->userId, 'detail' => ['id' => $id, 'name' => 'Porto']],
            ['action' => 'category_renamed', 'user_id' => $this->userId, 'detail' => ['from' => 'Porto', 'id' => $id, 'to' => 'Portokosten']],
            ['action' => 'category_deactivated', 'user_id' => $this->userId, 'detail' => ['id' => $id, 'name' => 'Portokosten']],
            ['action' => 'category_activated', 'user_id' => $this->userId, 'detail' => ['id' => $id, 'name' => 'Portokosten']],
        ], $this->entries());
    }

    public function test_change_and_audit_entry_share_one_transaction(): void
    {
        // Schlaegt das Protokoll fehl, darf die Aenderung nicht bestehen bleiben (unbekannter Benutzer: FK).
        $repo = new AccountRepository($this->pdo);
        try {
            $repo->create('Barkasse', 'cash', '0', '2026-01-01', 999999);
            $this->fail('Exception erwartet');
        } catch (\PDOException) {
        }
        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM accounts')->fetchColumn());

        $cats = new CategoryRepository($this->pdo);
        $id = $cats->create('Porto', $this->userId);
        try {
            $cats->rename($id, 'Neu', 999999);
            $this->fail('Exception erwartet');
        } catch (\PDOException) {
        }
        $this->assertSame('Porto', $this->pdo->query('SELECT name FROM categories')->fetchColumn());
    }

    public function test_unchanged_name_writes_no_rename_entry(): void
    {
        $repo = new CategoryRepository($this->pdo);
        $id = $repo->create('Porto', $this->userId);
        $repo->rename($id, 'Porto', $this->userId);

        $this->assertSame(['category_created'], array_column($this->entries(), 'action'));
    }

    public function test_new_actions_are_in_the_audit_filter_whitelist(): void
    {
        foreach ([
            'account_created', 'account_renamed', 'account_activated', 'account_deactivated',
            'category_created', 'category_renamed', 'category_activated', 'category_deactivated',
        ] as $action) {
            $this->assertSame($action, AuditLog::normalizeAction($action));
        }
        $this->assertNull(AuditLog::normalizeAction('account_exploded'));
    }
}
