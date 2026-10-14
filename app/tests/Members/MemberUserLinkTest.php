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
        $this->db->exec("INSERT INTO user_roles(user_id, role) VALUES (2, 'auditor')");
        $members = new MemberRepository($this->db);
        $members->save(null, ['name' => 'Erika Muster','email'=>'erika@example.org'], 0, 1);
        $members->save(null, ['name' => 'Max Mustermann','email'=>'max@example.org'], 0, 1);
        $this->link = new MemberUserLink($this->db);
    }

    public function test_link_is_optional_one_to_one_and_does_not_touch_roles(): void
    {
        $this->link->link(1, 2, 1);
        $this->assertSame(2, (int) $this->db->query('SELECT id FROM users WHERE member_id = 1')->fetchColumn());
        $this->assertSame(['auditor'], $this->db->query('SELECT role FROM user_roles WHERE user_id = 2')->fetchAll(PDO::FETCH_COLUMN));
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
