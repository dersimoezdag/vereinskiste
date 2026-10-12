<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Mail;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vereinskiste\Mail\Mailer;

final class SmtpTransportTest extends TestCase
{
    private string $directory;
    private mixed $server = null;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/vk-smtp-' . bin2hex(random_bytes(10));
        mkdir($this->directory, 0700);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'localhost'], $key, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        openssl_x509_export($certificate, $pem);
        openssl_pkey_export($key, $private);
        file_put_contents($this->directory . '/certificate.pem', $pem);
        file_put_contents($this->directory . '/key.pem', $private);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) { proc_terminate($this->server); proc_close($this->server); }
        foreach (glob($this->directory . '/*') ?: [] as $file) { unlink($file); }
        rmdir($this->directory);
    }

    public static function cases(): array
    {
        return [['tls', true, 'test-only', true], ['ssl', true, 'test-only', true],
            ['tls', false, 'test-only', false], ['ssl', false, 'test-only', false],
            ['tls', true, 'wrong-test-password', false]];
    }

    #[DataProvider('cases')]
    public function test_actual_tls_transport_authentication_and_certificate_rejection(
        string $mode, bool $trusted, string $password, bool $success
    ): void {
        $this->server = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/smtp_server.php', $this->directory, $mode],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $this->directory . '/error', 'w']], $pipes);
        for ($i = 0; $i < 100 && !is_file($this->directory . '/port'); $i++) { usleep(10000); }
        $this->assertFileExists($this->directory . '/port');
        $mailer = new Mailer(['host' => 'localhost', 'port' => (int) file_get_contents($this->directory . '/port'),
            'encryption' => $mode, 'from' => 'sender@example.org', 'username' => 'local-test', 'password' => $password],
            function (PHPMailer $mail) use ($trusted): void {
                if ($trusted) {
                    // Nur dieser ephemere Test vertraut seinem lokalen Zertifikat.
                    $mail->SMTPOptions = ['ssl' => ['cafile' => $this->directory . '/certificate.pem',
                        'verify_peer' => true, 'verify_peer_name' => true]];
                }
                $mail->Timeout = 2;
                $mail->send();
            });
        try {
            $mailer->send('recipient@example.org', 'Local SMTP check', 'Local test message');
            $this->assertTrue($success, 'Unsicherer Transport oder falsches Passwort wurde akzeptiert');
            $message = file_get_contents($this->directory . '/message');
            $this->assertStringContainsString('recipient@example.org', $message);
            $this->assertStringContainsString('Local test message', $message);
        } catch (Exception $e) {
            $this->assertFalse($success, $e->getMessage());
            $this->assertFileDoesNotExist($this->directory . '/message');
        }
    }
}
