<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Members\ApplicationRepository;
use Vereinskiste\Members\ApplicationSignatures;
use Vereinskiste\Tests\Support\ApplicationInput;
use Vereinskiste\Tests\TestCase;

final class ApplicationSignaturePagesTest extends TestCase
{
    use WebHarness;

    private PDO $pdo;
    private int $application;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->store = new ArraySessionStore();
        $this->app = Kernel::create(['pdo' => $this->pdo, 'session_store' => $this->store, 'require_https' => false, 'security_dir' => $this->securityDir()]);
        $this->webUser('admin@example.org', ['admin']);
        $repo = new ApplicationRepository($this->pdo);
        $invite = $repo->createInvite(1, 'Test', 7);
        $this->application = $repo->submit($invite['token'], ['first_name' => 'Erika', 'last_name' => 'Muster', 'email' => 'erika@example.org']
            + ApplicationInput::declarations($this->pdo, 'yes'));
    }

    public function test_decision_page_lists_declarations_with_signatures(): void
    {
        $this->loginAs(['secretary']);
        $html = (string) $this->get("/members/applications/{$this->application}/decision")->getBody();
        $this->assertStringContainsString('Antrag auf Mitgliedschaft', $html);
        $this->assertStringContainsString('Ich willige ein', $html);
        $this->assertStringContainsString('Erlangen', $html);
        $first = (new ApplicationSignatures($this->pdo))->forApplication($this->application)[0]['id'];
        $this->assertStringContainsString("/members/applications/{$this->application}/signatures/$first.png", $html);
        $this->assertStringContainsString("/members/applications/{$this->application}/pdf", $html);
    }

    public function test_signature_image_and_pdf_need_permission(): void
    {
        $first = (new ApplicationSignatures($this->pdo))->forApplication($this->application)[0]['id'];
        $this->loginAs(['secretary']);
        $png = $this->get("/members/applications/{$this->application}/signatures/$first.png");
        $this->assertSame('image/png', $png->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $png->getHeaderLine('Cache-Control'));
        $this->assertSame("\x89PNG", substr((string) $png->getBody(), 0, 4));
        $this->assertSame(404, $this->get("/members/applications/{$this->application}/signatures/999.png")->getStatusCode());

        $pdf = $this->get("/members/applications/{$this->application}/pdf");
        $this->assertSame('application/pdf', $pdf->getHeaderLine('Content-Type'));
        $body = (string) $pdf->getBody();
        $this->assertStringStartsWith('%PDF', $body);
        $this->assertStringContainsString('/Subtype /Image', $body);
        $this->assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM audit_log WHERE action = 'application_pdf_downloaded'")->fetchColumn());

        $this->pdo->exec("UPDATE user_roles SET role = 'treasurer'");
        $this->assertSame(403, $this->get("/members/applications/{$this->application}/pdf")->getStatusCode());
    }
}
