<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Install\GitHubReleases;
use Vereinskiste\Tests\TestCase;

final class UpdatePagesTest extends TestCase
{
    use WebHarness;
    private PDO $pdo;
    private int $calls = 0;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $checker = new GitHubReleases(function (): array {
            $this->calls++;
            return ['status' => 200, 'body' => json_encode(['tag_name' => 'v0.2.0',
                'draft' => false, 'prerelease' => false, 'assets' => [['name' => 'vereinskiste-0.2.0.zip',
                    'state' => 'uploaded', 'size' => 100, 'browser_download_url' =>
                        GitHubReleases::REPOSITORY . '/releases/download/v0.2.0/vereinskiste-0.2.0.zip']]])];
        });
        $this->store = new ArraySessionStore();
        $this->app = Kernel::create(['pdo' => $this->pdo, 'session_store' => $this->store,
            'security_dir' => $this->securityDir(), 'require_https' => false, 'release_checker' => $checker]);
    }

    public function test_check_requires_vorstand_and_csrf_and_runs_only_on_explicit_request(): void
    {
        $this->assertSame('/login', $this->get('/settings')->getHeaderLine('Location'));
        $this->loginAs(['kassenpruefer']);
        $this->assertSame(403, $this->postForm('/settings/check-updates', [])->getStatusCode());
        $this->assertSame(0, $this->calls);
        $this->pdo->exec("UPDATE user_roles SET role = 'admin'");
        $this->assertSame(200, $this->get('/settings')->getStatusCode());
        $this->assertSame(0, $this->calls);
        $this->assertSame('/settings', $this->postForm('/settings/check-updates', [])->getHeaderLine('Location'));
        $this->assertSame(1, $this->calls);
        $html = (string) $this->get('/settings')->getBody();
        $this->assertStringContainsString('Neue Version verfügbar:', $html);
        $this->assertStringContainsString('/releases/download/v0.2.0/vereinskiste-0.2.0.zip', $html);
    }
    public function test_error_page_explains_rate_limits_without_exposing_the_response(): void
    {
        $checker = new GitHubReleases(static fn (): array => ['status' => 429, 'body' => 'private-response-content']);
        $this->app = Kernel::create(['pdo' => $this->pdo,'session_store' => $this->store,'security_dir' => $this->securityDir(),
            'require_https' => false,'release_checker' => $checker]);
        $this->loginAs(['vorstand']);
        $this->assertSame(302, $this->postForm('/settings/check-updates', [])->getStatusCode());
        $html = (string) $this->get('/settings')->getBody();
        $this->assertStringContainsString('Hosting-IP', $html);
        $this->assertStringNotContainsString('private-response-content', $html);
    }
}
