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
        $speaker = $this->roleId('1. Vorsitzender');
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
        $this->assignments->assign($this->member, $this->roleId('2. Vorsitzender'), '', null, 1);
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
