<?php
declare(strict_types=1);

namespace Vereinskiste\Auth;

use PDO;
use RuntimeException;

/** Oeffentliche Anfragen enthalten keine Information ueber vorhandene Konten. */
final class ResetRequestQueue
{
    public function __construct(private PDO $pdo) {}

    public function enqueue(string $email): void
    {
        $this->pdo->exec("DELETE FROM password_reset_requests WHERE requested_at < now() - interval '15 minutes'");
        $stmt = $this->pdo->prepare('INSERT INTO password_reset_requests (email)
            SELECT ? WHERE (SELECT count(*) FROM password_reset_requests) < 500');
        $stmt->execute([$email]);
    }

    public function claim(): ?string
    {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Mailversand darf keine offene Transaktion verwenden.');
        }
        // Autocommit gibt jede Sperre frei, bevor SMTP aufgerufen wird.
        $stmt = $this->pdo->query("DELETE FROM password_reset_requests WHERE id =
            (SELECT id FROM password_reset_requests
             WHERE requested_at >= now() - interval '15 minutes'
             ORDER BY id FOR UPDATE SKIP LOCKED LIMIT 1) RETURNING email");
        $email = $stmt->fetchColumn();
        return $email === false ? null : (string) $email;
    }
}
