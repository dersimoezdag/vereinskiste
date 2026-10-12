<?php
declare(strict_types=1);

namespace Vereinskiste\Mail;

use Closure;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

/** Ausschliesslich SMTP; der injizierbare Transport erlaubt Tests ohne Mailversand. */
final class Mailer
{
    /** @param array<string,mixed> $config @param Closure(PHPMailer):void|null $transport */
    public function __construct(private array $config = [], private ?Closure $transport = null)
    {
    }

    public function isConfigured(): bool
    {
        return is_string($this->config['host'] ?? null) && trim($this->config['host']) !== ''
            && filter_var($this->config['from'] ?? '', FILTER_VALIDATE_EMAIL) !== false
            && in_array($this->config['encryption'] ?? 'tls', ['tls', 'ssl'], true)
            && (int) ($this->config['port'] ?? 587) > 0
            && (int) ($this->config['port'] ?? 587) <= 65535;
    }

    public function send(string $to, string $subject, string $body): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('SMTP ist nicht konfiguriert.');
        }
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->config['host'];
        $mail->Port = (int) ($this->config['port'] ?? 587);
        $mail->SMTPSecure = $this->config['encryption'] ?? PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAuth = ($this->config['username'] ?? '') !== '';
        $mail->Username = $this->config['username'] ?? '';
        $mail->Password = $this->config['password'] ?? '';
        $mail->Timeout = 10;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($this->config['from'], $this->config['from_name'] ?? 'Vereinskiste');
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $body;
        if ($this->transport !== null) {
            ($this->transport)($mail);
        } else {
            $mail->send();
        }
    }
}
