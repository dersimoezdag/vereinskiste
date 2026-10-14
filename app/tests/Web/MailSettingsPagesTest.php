<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Tests\TestCase;

final class MailSettingsPagesTest extends TestCase
{
    use WebHarness;
    private PDO $pdo;
    private string $instance;
    protected function setUp(): void
    {
        $this->pdo = $this->pdo();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->instance = sys_get_temp_dir() . '/vk-mail-web-' . bin2hex(random_bytes(8));
        $this->store = new ArraySessionStore();
        $this->app = Kernel::create(['app_dir' => $this->instance, 'pdo' => $this->pdo, 'session_store' => $this->store,
            'require_https' => false, 'security_dir' => $this->securityDir(), 'base_url' => 'https://verein.example.org']);
    }
    protected function tearDown(): void
    {
        foreach (glob($this->instance . '/config/*') ?: [] as $file) { unlink($file); }
        if (is_dir($this->instance . '/config')) { rmdir($this->instance . '/config'); }
        if (is_dir($this->instance)) { rmdir($this->instance); }
    }
    public function test_configuration_is_manage_only_and_password_is_never_rendered(): void
    {
        $this->loginAs(['kassenwart']);
        $this->assertSame(403, $this->get('/settings/email')->getStatusCode());
        $this->assertSame(403, $this->postForm('/settings/email', [])->getStatusCode());
        $this->pdo->exec("UPDATE user_roles SET role='admin'");
        $this->assertSame(200, $this->get('/settings/email')->getStatusCode());
        $input = ['version' => '0', 'enabled' => '1', 'host' => 'smtp.example.org', 'port' => '587',
            'from' => 'noreply@example.org', 'encryption' => 'tls', 'password' => 'private-smtp-password'];
        $this->assertSame('/settings/email', $this->postForm('/settings/email', $input)->getHeaderLine('Location'));
        $html = (string) $this->get('/settings/email')->getBody();
        $this->assertStringContainsString('noreply@example.org', $html);
        $this->assertStringNotContainsString('private-smtp-password', $html);
        $input['version'] = '1'; $input['from'] = 'invalid';
        $res = $this->postForm('/settings/email', $input);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringNotContainsString('private-smtp-password', (string) $res->getBody());
    }

    public function test_test_mail_uses_form_values_and_reports_result(): void
    {
        $this->pdo->exec("UPDATE user_roles SET role='admin'");
        $this->loginAs(['admin']);
        $sent = [];
        $app = Kernel::create(['app_dir' => $this->instance, 'pdo' => $this->pdo, 'session_store' => $this->store,
            'require_https' => false, 'security_dir' => $this->securityDir(), 'base_url' => 'https://verein.example.org',
            'mail_test_factory' => static function (array $config) use (&$sent): \Vereinskiste\Mail\Mailer {
                return new \Vereinskiste\Mail\Mailer($config, static function ($mail) use (&$sent): void {
                    if ($mail->Host === 'broken.example.org') { throw new \RuntimeException('Verbindung fehlgeschlagen'); }
                    $sent[] = array_column($mail->getToAddresses(), 0);
                });
            }]);
        $this->app = $app;
        $input = ['host' => 'smtp.example.org', 'port' => '587', 'from' => 'noreply@example.org', 'encryption' => 'tls',
            'test_to' => 'kasse@example.org'];
        $html = (string) $this->postForm('/settings/email/test', $input)->getBody();
        $this->assertSame([['kasse@example.org']], $sent);
        $this->assertStringContainsString('kasse@example.org', $html);
        $res = $this->postForm('/settings/email/test', ['host' => 'broken.example.org'] + $input);
        $this->assertStringContainsString('Verbindung fehlgeschlagen', (string) $res->getBody());
    }
}
