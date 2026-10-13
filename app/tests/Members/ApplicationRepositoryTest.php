<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Members;

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
            'consent_statute' => '1',
            'consent_privacy' => '1',
        ]);
        $this->assertGreaterThan(0, $appId);

        // Second submission with same token must fail
        $this->expectException(ValidationError::class);
        $this->repo->submit($invite['token'], [
            'name' => 'Erika Muster',
            'email' => 'erika@example.org',
            'consent_statute' => '1',
            'consent_privacy' => '1',
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
                'consent_statute' => '1',
                'consent_privacy' => '1',
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
            'consent_statute' => '1',
            'consent_privacy' => '1',
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
            'consent_statute' => '1',
            'consent_privacy' => '1',
        ]);
    }

    public function test_approve_creates_member_and_sets_active_role(): void
    {
        $invite = $this->repo->createInvite(1, 'Antrag 1', 14, null, false);
        $appId = $this->repo->submit($invite['token'], [
            'name' => 'Anna Neumitglied',
            'email' => 'anna@example.org',
            'city' => 'Hamburg',
            'consent_statute' => '1',
            'consent_privacy' => '1',
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

    public function test_reject_records_reason(): void
    {
        $invite = $this->repo->createInvite(1, 'Antrag 2', 14, null, false);
        $appId = $this->repo->submit($invite['token'], [
            'name' => 'Bernd Abgelehnt',
            'email' => 'bernd@example.org',
            'consent_statute' => '1',
            'consent_privacy' => '1',
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
            'email' => 'erika@example.org', 'consent_statute' => '1', 'consent_privacy' => '1',
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
                'consent_statute' => '1', 'consent_privacy' => '1',
            ]);
            $this->fail('Vorname fehlt');
        } catch (ValidationError $e) {
            $this->assertArrayHasKey('first_name', $e->errors);
        }
    }
}
