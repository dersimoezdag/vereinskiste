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
