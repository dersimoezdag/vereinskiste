<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vereinskiste\Mail\Mailer;

final class MailerTest extends TestCase
{
    public function test_transport_is_smtp_with_tls_and_utf8(): void
    {
        $sent = false;
        $mailer = new Mailer(['host' => 'smtp.example.org', 'from' => 'verein@example.org',
            'username' => 'user', 'password' => 'secret'], function (PHPMailer $mail) use (&$sent): void {
            $this->assertSame('smtp', $mail->Mailer);
            $this->assertSame('tls', $mail->SMTPSecure);
            $this->assertSame(587, $mail->Port);
            $this->assertTrue($mail->SMTPAuth);
            $this->assertSame('UTF-8', $mail->CharSet);
            $this->assertSame([['user@example.org', '']], $mail->getToAddresses());
            $this->assertSame('Nachricht', $mail->Body);
            $sent = true;
        });
        $this->assertTrue($mailer->isConfigured());
        $mailer->send('user@example.org', 'Betreff', 'Nachricht');
        $this->assertTrue($sent);
    }

    public function test_implicit_tls_is_supported(): void
    {
        (new Mailer(['host' => 'smtp.example.org', 'from' => 'verein@example.org',
            'encryption' => 'ssl', 'port' => 465], function (PHPMailer $mail): void {
            $this->assertSame('ssl', $mail->SMTPSecure);
            $this->assertSame(465, $mail->Port);
        }))->send('user@example.org', 'Betreff', 'Text');
    }

    public function test_missing_or_unencrypted_config_is_disabled(): void
    {
        $this->assertFalse((new Mailer())->isConfigured());
        $this->assertFalse((new Mailer(['host' => 'smtp.example.org', 'from' => 'verein@example.org',
            'encryption' => '']))->isConfigured());
        $this->expectException(RuntimeException::class);
        (new Mailer())->send('user@example.org', 'Betreff', 'Text');
    }
}
