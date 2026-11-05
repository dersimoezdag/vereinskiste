<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Members;

use Vereinskiste\Tests\Support\ApplicationInput;
use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\ApplicationRepository;
use Vereinskiste\Members\MemberRepository;
use Vereinskiste\Members\MemberRoleAssignments;
use Vereinskiste\Tests\TestCase;

final class ApplicationRepositoryTest extends TestCase
{
    private PDO $db;
    private ApplicationRepository $repo;

    protected function setUp(): void
    {
        $this->db = $this->pdo();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->db->exec("INSERT INTO users(name,email,password_hash) VALUES ('Admin','admin@example.org','hash')");
        $this->repo = new ApplicationRepository($this->db);
    }

    public function test_revoked_links_are_hidden_and_cannot_be_submitted(): void
    {
        $open = $this->repo->createInvite(1, 'Open');
        $expired = $this->repo->createInvite(1, 'Expired');
        $revoked = $this->repo->createInvite(1, 'Revoked', 14, 'secret', false, true);
        $this->db->prepare("UPDATE membership_application_invites SET expires_at=CURRENT_TIMESTAMP - INTERVAL '1 day' WHERE id=?")->execute([$expired['id']]);
        $this->assertTrue($this->repo->revokeInvite($revoked['id'], 1));
        $this->assertFalse($this->repo->revokeInvite($revoked['id'], 1));
        $this->assertFalse($this->repo->revokeInvite(999999, 1));
        $this->assertSame([$open['id']], array_column($this->repo->listInvites(), 'id'));
        $this->assertCount(3, $this->repo->listInvites(true));
        $invite = $this->repo->findInviteByToken($revoked['token']);
        $this->assertTrue($invite['is_revoked']);
        $this->assertFalse($invite['is_valid']);
        $this->assertSame(1, (int) $this->db->query("SELECT count(*) FROM audit_log WHERE action='membership_invite_revoked'")->fetchColumn());
        try {
            $this->repo->submit($revoked['token'], []);
            $this->fail('Revoked invite accepted');
        } catch (ValidationError $e) {
            $this->assertSame('applications.link_revoked', $e->errors['token']);
        }
        $this->assertSame(0, (int) $this->db->query('SELECT count(*) FROM membership_applications')->fetchColumn());
    }

    public function test_multi_use_invite_stops_after_configured_number_of_applications(): void
    {
        $invite = $this->repo->createInvite(1, 'Two applications', 14, null, false, false, 2);
        $this->assertSame(2, $invite['max_uses']);
        for ($i = 0; $i < 2; $i++) {
            $this->assertTrue($this->repo->findInviteByToken($invite['token'])['is_valid']);
            $this->repo->submit($invite['token'], ['name' => 'Example Person', 'email' => "person$i@example.org", ...ApplicationInput::defaults($this->db)]);
        }
        $found = $this->repo->findInviteByToken($invite['token']);
        $this->assertSame(2, $found['used_count']);
        $this->assertFalse($found['is_valid']);
        $this->expectException(ValidationError::class);
        $this->repo->submit($invite['token'], []);
    }

    public function test_create_invite_and_verify_password(): void
    {
        $invite = $this->repo->createInvite(1, 'Interessent A', 7, 'Geheim123', true);
        $this->assertNotEmpty($invite['token']);
        $this->assertTrue($invite['require_sepa']);
        $this->assertTrue($invite['has_password']);
        $this->assertTrue($this->repo->verifyInvitePassword($invite['token'], 'Geheim123'));
        $this->assertFalse($this->repo->verifyInvitePassword($invite['token'], 'Falsch'));

        $found = $this->repo->findInviteByToken($invite['token']);
        $this->assertNotNull($found);
        $this->assertTrue($found['is_valid']);
        $this->assertFalse($found['is_expired']);
        $this->assertFalse($found['is_used']);
    }

    public function test_submit_one_time_link_cannot_be_used_twice(): void
    {
        $invite = $this->repo->createInvite(1, 'Test Einmal-Link', 14, null, false);
        $appId = $this->repo->submit($invite['token'], [
            'name' => 'Max Mustermann',
            'email' => 'max@example.org',
            ...ApplicationInput::defaults($this->db),
        ]);
        $this->assertGreaterThan(0, $appId);

        // Second submission with same token must fail
        $this->expectException(ValidationError::class);
        $this->repo->submit($invite['token'], [
            'name' => 'Erika Muster',
            'email' => 'erika@example.org',
            ...ApplicationInput::defaults($this->db),
        ]);
    }

    public function test_submit_requires_sepa_when_configured(): void
    {
        $invite = $this->repo->createInvite(1, 'Mit SEPA', 7, null, true);

        // Missing SEPA details
        try {
            $this->repo->submit($invite['token'], [
                'name' => 'Max Mustermann',
                'email' => 'max@example.org',
                ...ApplicationInput::defaults($this->db),
            ]);
            $this->fail('SEPA details should be required');
        } catch (ValidationError $e) {
            $this->assertArrayHasKey('account_holder', $e->errors);
            $this->assertArrayHasKey('iban', $e->errors);
            $this->assertArrayHasKey('sepa_mandate_consent', $e->errors);
        }

        // With valid SEPA
        $appId = $this->repo->submit($invite['token'], [
            'name' => 'Max Mustermann',
            'email' => 'max@example.org',
            ...ApplicationInput::defaults($this->db),
            'account_holder' => 'Max Mustermann',
            'iban' => 'DE89370400440532013000',
            'bic' => 'BYLADEM1001',
            'sepa_mandate_consent' => '1',
        ]);
        $this->assertGreaterThan(0, $appId);
        $app = $this->repo->findApplication($appId);
        $this->assertSame('Max Mustermann', $app['applicant_name']);
        $this->assertSame('DE89370400440532013000', $app['data']['iban']);
    }

    public function test_expired_invite_is_rejected(): void
    {
        $invite = $this->repo->createInvite(1, 'Abgelaufen', 1, null, false);
        $this->db->exec("UPDATE membership_application_invites SET expires_at = now() - interval '1 hour' WHERE id = {$invite['id']}");

        $found = $this->repo->findInviteByToken($invite['token']);
        $this->assertTrue($found['is_expired']);
        $this->assertFalse($found['is_valid']);

        $this->expectException(ValidationError::class);
        $this->repo->submit($invite['token'], [
            'name' => 'Max',
            'email' => 'max@example.org',
            ...ApplicationInput::defaults($this->db),
        ]);
    }

    public function test_approve_creates_member_and_sets_active_role(): void
    {
        $invite = $this->repo->createInvite(1, 'Antrag 1', 14, null, false);
        $appId = $this->repo->submit($invite['token'], [
            ...ApplicationInput::defaults($this->db),
            'name' => 'Anna Neumitglied',
            'email' => 'anna@example.org',
            'city' => 'Hamburg',
        ]);

        $this->assertSame(1, $this->repo->countPending());

        $memberId = $this->repo->approve($appId, 1);
        $this->assertGreaterThan(0, $memberId);

        $member = (new MemberRepository($this->db))->find($memberId);
        $this->assertSame('Anna Neumitglied', $member['name']);
        $this->assertSame('Hamburg', $member['data']['city']);
        $this->assertTrue($member['current']);

        // Check active_member role was assigned automatically
        $assignments = (new MemberRoleAssignments($this->db))->forMember($memberId);
        $this->assertSame('Aktives Mitglied', $assignments[0]['role_name']);

        $app = $this->repo->findApplication($appId);
        $this->assertSame('approved', $app['status']);
        $this->assertSame($memberId, $app['member_id']);
        $this->assertSame(0, $this->repo->countPending());
    }

    public function test_approve_uses_chosen_join_date_and_membership_type(): void
    {
        $invite = $this->repo->createInvite(1, 'Antrag Passiv', 14, null, false);
        $appId = $this->repo->submit($invite['token'], [...ApplicationInput::defaults($this->db), 'name' => 'Paula Passiv', 'email' => 'paula@example.org']);
        $passive = (int) $this->db->query("SELECT id FROM club_roles WHERE system_key='passive_member'")->fetchColumn();

        $memberId = $this->repo->approve($appId, 1, null, '2026-12-01', $passive);

        $this->assertSame('2026-12-01', $this->db->query("SELECT start_date FROM membership_periods WHERE member_id=$memberId")->fetchColumn());
        $assignments = (new MemberRoleAssignments($this->db))->forMember($memberId);
        $this->assertCount(1, $assignments);
        $this->assertSame('Passives Mitglied', $assignments[0]['role_name']);
        $this->assertSame('2026-12-01', $assignments[0]['start_date']);
    }

    public function test_approve_rejects_former_function_and_unknown_types_and_invalid_dates(): void
    {
        $invite = $this->repo->createInvite(1, 'Antrag Falsch', 14, null, false);
        $appId = $this->repo->submit($invite['token'], [...ApplicationInput::defaults($this->db), 'name' => 'Falk Falsch', 'email' => 'falk@example.org']);
        $former = (int) $this->db->query("SELECT id FROM club_roles WHERE system_key='former_member'")->fetchColumn();
        $function = (int) $this->db->query("SELECT id FROM club_roles WHERE kind='function' LIMIT 1")->fetchColumn();
        $cases = [['2026-12-01', $former, 'membership_type_id'], ['2026-12-01', $function, 'membership_type_id'],
            ['2026-12-01', 999999, 'membership_type_id'], ['2026-02-30', null, 'join_date'], ['morgen', null, 'join_date']];
        foreach ($cases as [$date, $type, $field]) {
            try {
                $this->repo->approve($appId, 1, null, $date, $type);
                $this->fail("$date/$type akzeptiert");
            } catch (ValidationError $e) {
                $this->assertArrayHasKey($field, $e->errors);
            }
        }
        $this->assertSame('pending', $this->repo->findApplication($appId)['status']);
        $this->assertSame(0, (int) $this->db->query('SELECT count(*) FROM members')->fetchColumn());
    }

    public function test_selectable_membership_types_exclude_former_and_functions(): void
    {
        $names = array_column($this->repo->membershipTypes(), 'name');
        $this->assertContains('Aktives Mitglied', $names);
        $this->assertContains('Passives Mitglied', $names);
        $this->assertNotContains('Ehemaliges Mitglied', $names);
        $this->assertNotContains('Ehrenmitglied', $names);
    }

    public function test_reject_records_reason(): void
    {
        $invite = $this->repo->createInvite(1, 'Antrag 2', 14, null, false);
        $appId = $this->repo->submit($invite['token'], [
            'name' => 'Bernd Abgelehnt',
            'email' => 'bernd@example.org',
            ...ApplicationInput::defaults($this->db),
        ]);

        $this->repo->reject($appId, 'Aufnahmestopp', 1);
        $app = $this->repo->findApplication($appId);
        $this->assertSame('rejected', $app['status']);
        $this->assertSame('Aufnahmestopp', $app['rejection_reason']);
        $this->assertSame(0, $this->repo->countPending());
    }

    public function test_application_with_separate_name_fields_becomes_a_member_with_the_same_fields(): void
    {
        $invite = $this->repo->createInvite(1, 'Getrennte Namen', 14, null, false);
        $appId = $this->repo->submit($invite['token'], [
            'salutation' => 'frau', 'title' => 'Dr.', 'first_name' => 'Erika', 'last_name' => 'Muster',
            'email' => 'erika@example.org', ...ApplicationInput::defaults($this->db),
        ]);
        $app = $this->repo->findApplication($appId);
        $this->assertSame('Dr. Erika Muster', $app['applicant_name']);
        $this->assertSame('Erika', $app['data']['first_name']);

        $member = (new MemberRepository($this->db))->find($this->repo->approve($appId, 1));
        $this->assertSame('Dr. Erika Muster', $member['name']);
        $this->assertSame('frau', $member['data']['salutation']);
        $this->assertSame('Muster', $member['data']['last_name']);
    }

    public function test_submit_requires_first_and_last_name(): void
    {
        $invite = $this->repo->createInvite(1, 'Pflichtnamen', 14, null, false);
        try {
            $this->repo->submit($invite['token'], [
                'first_name' => '', 'last_name' => 'Muster', 'email' => 'e@example.org',
                ...ApplicationInput::defaults($this->db),
            ]);
            $this->fail('Vorname fehlt');
        } catch (ValidationError $e) {
            $this->assertArrayHasKey('first_name', $e->errors);
        }
    }

    public function test_house_number_is_stored_and_transferred_to_member(): void
    {
        $repo = new ApplicationRepository($this->db);
        $invite = $repo->createInvite(1, 'Test', 7);
        $id = $repo->submit($invite['token'], ['first_name' => 'E', 'last_name' => 'M', 'email' => 'e@example.org',
            'address' => 'Musterstraße', 'house_number' => '12a', 'zip' => '91054', 'city' => 'Erlangen'] + ApplicationInput::defaults($this->db));
        $this->assertSame('12a', $repo->findApplication($id)['data']['house_number']);
        $member = $repo->approve($id, 1);
        $data = json_decode((string) $this->db->query("SELECT data FROM members WHERE id = $member")->fetchColumn(), true);
        $this->assertSame(['Musterstraße', '12a'], [$data['address'], $data['house_number']]);
    }
}
