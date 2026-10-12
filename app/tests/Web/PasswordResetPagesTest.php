<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PDO;
use PHPMailer\PHPMailer\PHPMailer;
use Slim\Psr7\Factory\ServerRequestFactory;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Mail\Mailer;
use Vereinskiste\Tests\TestCase;

final class PasswordResetPagesTest extends TestCase
{
    use WebHarness;

    private PDO $pdo;
    private array $messages = [];
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->userId = $this->webUser('user@example.org', ['vorstand']);
        $this->configure();
    }

    private function configure(bool $enabled = true, string $baseUrl = 'https://verein.example.org'): void
    {
        $this->store = new ArraySessionStore();
        $mailer = new Mailer($enabled ? ['host' => 'smtp.example.org', 'from' => 'verein@example.org'] : [],
            function (PHPMailer $mail): void {
                $this->messages[] = $mail;
            });
        $this->app = Kernel::create(['pdo' => $this->pdo, 'session_store' => $this->store,
            'mailer' => $mailer, 'base_url' => $baseUrl]);
    }

    private function requestToken(): string
    {
        $this->postForm('/password-reset', ['email' => 'user@example.org']);
        $this->assertCount(1, $this->messages);
        preg_match('~/password-reset/([a-f0-9]{64})~', $this->messages[0]->Body, $match);
        return $match[1];
    }

    public function test_unknown_and_inactive_email_show_same_confirmation_and_send_nothing(): void
    {
        $known = $this->postForm('/password-reset', ['email' => 'user@example.org']);
        $unknown = $this->postForm('/password-reset', ['email' => 'unknown@example.org']);
        $this->assertSame((string) $known->getBody(), (string) $unknown->getBody());
        $this->pdo->exec('UPDATE users SET active = false');
        $inactive = $this->postForm('/password-reset', ['email' => 'user@example.org']);
        $this->assertSame((string) $known->getBody(), (string) $inactive->getBody());
        $this->assertCount(1, $this->messages);
    }

    public function test_token_is_hashed_single_use_and_password_changes(): void
    {
        $otherBrowser = new ArraySessionStore();
        $otherAuth = new \Vereinskiste\Auth\AuthService(new \Vereinskiste\Auth\UserRepository($this->pdo), $otherBrowser);
        $this->assertNotNull($otherAuth->login('user@example.org', 'richtig-richtig'));
        $token = $this->requestToken();
        $this->assertSame(hash('sha256', $token), $this->pdo->query('SELECT token_hash FROM password_resets')->fetchColumn());
        $page = $this->get('/password-reset/' . $token);
        $this->assertStringContainsString('name="password"', (string) $page->getBody());
        $this->assertSame('no-referrer', $page->getHeaderLine('Referrer-Policy'));
        $this->assertSame('no-store', $page->getHeaderLine('Cache-Control'));
        $response = $this->postForm('/password-reset/' . $token,
            ['password' => 'neues-passwort-123', 'confirmation' => 'neues-passwort-123']);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(password_verify('neues-passwort-123', $this->pdo->query('SELECT password_hash FROM users')->fetchColumn()));
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM password_resets')->fetchColumn());
        $this->assertSame(422, $this->postForm('/password-reset/' . $token,
            ['password' => 'anderes-passwort', 'confirmation' => 'anderes-passwort'])->getStatusCode());
        $this->assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM audit_log WHERE action = 'password_reset_completed'")->fetchColumn());
        $nextRequestAuth = new \Vereinskiste\Auth\AuthService(new \Vereinskiste\Auth\UserRepository($this->pdo), $otherBrowser);
        $this->assertNull($nextRequestAuth->currentUser(), 'Reset beendet auch bereits angemeldete andere Browser');
    }

    public function test_expired_token_and_changed_credentials_are_rejected(): void
    {
        $token = $this->requestToken();
        $this->pdo->exec("UPDATE password_resets SET expires_at = now() - interval '1 second'");
        $this->assertStringNotContainsString('name="password"', (string) $this->get('/password-reset/' . $token)->getBody());
        $this->pdo->exec("UPDATE password_resets SET expires_at = now() + interval '1 hour'");
        $this->pdo->exec("UPDATE users SET email = 'changed@example.org'");
        $this->assertStringNotContainsString('name="password"', (string) $this->get('/password-reset/' . $token)->getBody());
        $this->pdo->exec("UPDATE users SET email = 'user@example.org', password_hash = 'changed'");
        $this->assertStringNotContainsString('name="password"', (string) $this->get('/password-reset/' . $token)->getBody());
    }

    public function test_validation_keeps_token_and_never_prefills_password(): void
    {
        $token = $this->requestToken();
        foreach ([['short', 'short'], ['langes-passwort-123', 'different']] as [$password, $confirm]) {
            $response = $this->postForm('/password-reset/' . $token,
                ['password' => $password, 'confirmation' => $confirm]);
            $this->assertSame(422, $response->getStatusCode());
            $this->assertStringNotContainsString('value="' . $password . '"', (string) $response->getBody());
        }
        $this->assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM password_resets')->fetchColumn());
    }

    public function test_without_smtp_reset_is_disabled_and_login_link_hidden(): void
    {
        $this->configure(false);
        $this->assertStringNotContainsString('href="/password-reset"', (string) $this->get('/login')->getBody());
        $this->assertStringContainsString('Bitte wende dich an einen Vorstand', (string) $this->get('/password-reset')->getBody());
        $this->postForm('/password-reset', ['email' => 'user@example.org']);
        $this->assertCount(0, $this->messages);
    }

    public function test_reset_uses_configured_origin_and_requires_https(): void
    {
        $this->assertStringContainsString('href="/password-reset"', (string) $this->get('/login')->getBody());
        $this->requestToken();
        $this->assertStringContainsString('https://verein.example.org/password-reset/', $this->messages[0]->Body);
        $this->configure(true, 'http://verein.example.org');
        $this->assertStringNotContainsString('href="/password-reset"', (string) $this->get('/login')->getBody());
    }

    public function test_requests_are_throttled_and_new_token_replaces_old_one(): void
    {
        $old = $this->requestToken();
        $this->postForm('/password-reset', ['email' => 'user@example.org']);
        $this->assertCount(1, $this->messages);
        $this->pdo->exec("UPDATE password_resets SET created_at = now() - interval '2 minutes'");
        $this->postForm('/password-reset', ['email' => 'user@example.org']);
        $this->assertCount(2, $this->messages);
        $this->assertStringNotContainsString('name="password"', (string) $this->get('/password-reset/' . $old)->getBody());
    }

    public function test_post_requires_csrf_and_malformed_token_is_rejected(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/password-reset')
            ->withParsedBody(['email' => 'user@example.org']);
        $this->assertSame(403, $this->app->handle($request)->getStatusCode());
        $this->assertCount(0, $this->messages);
        $this->assertStringNotContainsString('name="password"', (string) $this->get('/password-reset/not-a-token')->getBody());
    }
}
