<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Members\ApplicationRepository;
use Vereinskiste\Tests\Support\ApplicationInput;
use Vereinskiste\Tests\TestCase;

final class ApplicantPdfDownloadTest extends TestCase
{
    use WebHarness;

    private PDO $pdo;
    private ApplicationRepository $repo;
    private int $actor;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->bootApp();
        $this->actor = $this->webUser('admin@example.org', ['admin']);
        $this->repo = new ApplicationRepository($this->pdo);
    }

    private function input(): array
    {
        return ['first_name' => 'Erika', 'last_name' => 'Muster', 'email' => 'erika@example.org']
            + ApplicationInput::declarations($this->pdo, 'yes');
    }

    public function test_applicant_can_download_signed_pdf_only_in_submitting_session(): void
    {
        $invite = $this->repo->createInvite($this->actor, 'Antrag', 7);
        $this->assertSame(302, $this->postForm('/antrag/' . $invite['token'], $this->input())->getStatusCode());
        $id = (int) $this->pdo->query('SELECT id FROM membership_applications')->fetchColumn();
        $this->assertStringContainsString('href="/antrag/pdf"', (string) $this->get('/antrag/danke')->getBody());
        $pdf = $this->get('/antrag/pdf');
        $this->assertSame(200, $pdf->getStatusCode());
        $this->assertSame('application/pdf', $pdf->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $pdf->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('mitgliedsantrag-' . $id . '.pdf', $pdf->getHeaderLine('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', (string) $pdf->getBody());
        $this->assertStringContainsString('/Subtype /Image', (string) $pdf->getBody());
        $this->assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM audit_log WHERE action='application_pdf_downloaded' AND user_id IS NULL")->fetchColumn());

        $this->bootApp();
        $this->get('/antrag/' . $invite['token']);
        $this->assertSame(404, $this->get('/antrag/pdf?id=' . $id)->getStatusCode());
        $this->assertStringNotContainsString('href="/antrag/pdf"', (string) $this->get('/antrag/danke')->getBody());
    }

    public function test_shared_invite_does_not_grant_access_to_another_application(): void
    {
        $invite = $this->repo->createInvite($this->actor, 'Mehrfach', 7, null, false, false, 5);
        $this->postForm('/antrag/' . $invite['token'], $this->input());
        $firstId = $this->store->get('application_pdf')['id'];
        $this->bootApp();
        $this->assertSame(404, $this->get('/antrag/pdf')->getStatusCode());
        $this->postForm('/antrag/' . $invite['token'], $this->input());
        $secondId = $this->store->get('application_pdf')['id'];
        $this->assertNotSame($firstId, $secondId);
        $this->assertStringContainsString('mitgliedsantrag-' . $secondId . '.pdf',
            $this->get('/antrag/pdf?id=' . $firstId)->getHeaderLine('Content-Disposition'));
    }

    public function test_expired_or_anonymized_application_is_not_downloadable(): void
    {
        $invite = $this->repo->createInvite($this->actor, 'Antrag', 7);
        $this->postForm('/antrag/' . $invite['token'], $this->input());
        $grant = $this->store->get('application_pdf');
        $this->store->set('application_pdf', ['id' => $grant['id'], 'expires' => time() - 1]);
        $this->assertSame(404, $this->get('/antrag/pdf')->getStatusCode());
        $this->store->set('application_pdf', $grant);
        $this->pdo->exec('UPDATE membership_applications SET anonymized_at = now()');
        $this->assertSame(404, $this->get('/antrag/pdf')->getStatusCode());
        $this->assertStringNotContainsString('href="/antrag/pdf"', (string) $this->get('/antrag/danke')->getBody());
    }

    public function test_invalid_test_and_bot_submissions_do_not_offer_a_download(): void
    {
        $invite = $this->repo->createInvite($this->actor, 'Antrag', 7);
        $this->assertSame(422, $this->postForm('/antrag/' . $invite['token'], ['email' => 'invalid'])->getStatusCode());
        $this->assertSame(404, $this->get('/antrag/pdf')->getStatusCode());
        $this->postForm('/antrag/' . $invite['token'], $this->input() + ['website' => 'bot']);
        $this->assertSame(404, $this->get('/antrag/pdf')->getStatusCode());
        $test = $this->repo->createInvite($this->actor, 'Test', 7, null, false, true);
        $this->postForm('/antrag/' . $test['token'], $this->input());
        $this->assertSame(404, $this->get('/antrag/pdf')->getStatusCode());
        $this->assertStringNotContainsString('href="/antrag/pdf"', (string) $this->get('/antrag/danke?test=1')->getBody());
    }
}
