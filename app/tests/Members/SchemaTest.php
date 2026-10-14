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
        $this->db->exec("INSERT INTO members(name,data) VALUES ('A','{\"email\":\"member@example.org\"}')");
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
        $this->assertSame(['active_member', 'passive_member', 'former_member', null, 'finance_board_member', 'first_chairperson', 'second_chairperson'], array_column($rows, 'system_key'));
        $this->assertSame(['Aktives Mitglied', 'Passives Mitglied', 'Ehemaliges Mitglied', 'Ehrenmitglied',
            'Finanzvorstand', '1. Vorsitzender', '2. Vorsitzender'], array_column($rows, 'name'));
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

    public function test_membership_applications_table_structure(): void
    {
        $this->db->exec("INSERT INTO users(name,email,password_hash) VALUES ('Admin','admin@example.org','hash')");
        $this->db->exec("INSERT INTO membership_application_invites(token, title, require_sepa, expires_at, created_by)
            VALUES ('abcdefabcdefabcdefabcdefabcdefabcdef', 'Test', true, now() + interval '7 days', 1)");
        $this->db->exec("INSERT INTO membership_applications(invite_id, applicant_name, email, data) VALUES (1, 'Max Mustermann', 'max@example.org', '{\"city\":\"Berlin\"}')");
        $row = $this->db->query("SELECT a.status, a.applicant_name, a.email, a.data->>'city' as city, i.require_sepa
            FROM membership_applications a JOIN membership_application_invites i ON i.id = a.invite_id WHERE a.id = 1")->fetch();
        $this->assertSame('pending', $row['status']);
        $this->assertSame('Max Mustermann', $row['applicant_name']);
        $this->assertSame('Berlin', $row['city']);
        $this->assertTrue((bool) $row['require_sepa']);
    }
}
