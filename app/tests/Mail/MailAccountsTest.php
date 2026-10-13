<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Mail\MailAccounts;

final class MailAccountsTest extends TestCase
{
    private string $directory;
    protected function setUp(): void { $this->directory = sys_get_temp_dir() . '/vk-mail-' . bin2hex(random_bytes(8)); }
    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) { unlink($file); }
        if (is_dir($this->directory)) { rmdir($this->directory); }
    }
    private function config(): array
    {
        return ['host' => 'smtp.example.org', 'port' => '587', 'from' => 'noreply@example.org',
            'from_name' => 'Verein', 'username' => 'system', 'encryption' => 'tls', 'enabled' => '1'];
    }
    public function test_legacy_fallback_and_password_retention_with_atomic_versioned_updates(): void
    {
        $accounts = new MailAccounts($this->directory, $this->config() + ['password' => 'secret']);
        $this->assertSame('secret', $accounts->configuration()['password']);
        $this->assertSame([], $accounts->configuration('newsletter'));
        $accounts->saveSystem($this->config(), 0);
        $this->assertSame('secret', (new MailAccounts($this->directory))->configuration()['password']);
        $this->assertSame(0600, fileperms($this->directory . '/mail-accounts.json') & 0777);
        $accounts->saveSystem($this->config() + ['password' => 'new-secret'], 1);
        $this->assertSame('new-secret', $accounts->configuration()['password']);
        $this->expectException(Conflict::class);
        $accounts->saveSystem($this->config(), 1);
    }
    public function test_disable_overrides_legacy_and_password_can_be_cleared(): void
    {
        $accounts = new MailAccounts($this->directory, $this->config() + ['password' => 'secret']);
        $input = $this->config(); $input['enabled'] = '0'; $input['clear_password'] = '1';
        $accounts->saveSystem($input, 0);
        $this->assertSame([], $accounts->configuration());
        $this->assertSame('', $accounts->get()['config']['password']);
    }
    public function test_invalid_settings_are_not_written(): void
    {
        $accounts = new MailAccounts($this->directory);
        foreach (['port' => '65536', 'from' => 'bad-address', 'host' => "smtp.example.org\r\n", 'encryption' => 'off'] as $field => $value) {
            $input = $this->config(); $input[$field] = $value;
            // Embedded controls in a hostname are invalid; trailing whitespace is normalized by Fields.
            if ($field === 'host') { $input[$field] = "smtp.\r\nexample.org"; }
            try { $accounts->saveSystem($input, 0); $this->fail('Invalid ' . $field); }
            catch (ValidationError $e) { $this->assertArrayHasKey($field, $e->errors); }
        }
        $this->assertSame(0, $accounts->get()['version']);
        $this->assertFileDoesNotExist($this->directory . '/mail-accounts.json');
    }
}
