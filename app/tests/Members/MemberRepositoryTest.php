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
