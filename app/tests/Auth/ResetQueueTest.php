<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Auth;

use PDO;
use PHPMailer\PHPMailer\PHPMailer;
use Vereinskiste\Auth\PasswordResetService;
use Vereinskiste\Auth\RateLimiter;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Mail\Mailer;
use Vereinskiste\Tests\TestCase;

final class ResetQueueTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = $this->pdo();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->db->exec("INSERT INTO users (name, email, password_hash) VALUES ('User', 'user@example.org', 'hash')");
    }

    private function service(PDO $pdo, \Closure $transport): PasswordResetService
    {
        return new PasswordResetService(static fn (): PDO => $pdo,
            new Mailer(['host' => 'localhost', 'from' => 'test@example.org'], $transport),
            'https://verein.example.org', require dirname(__DIR__, 2) . '/src/Text/de.php',
            new RateLimiter($this->securityDir()));
    }

    public function test_http_path_enqueues_unknown_known_and_inactive_without_lookup_or_smtp(): void
    {
        $this->db->exec("INSERT INTO users (name, email, password_hash, active) VALUES ('Inactive', 'inactive@example.org', 'hash', false)");
        $service = $this->service($this->db, static function (): void { throw new \LogicException('SMTP in HTTP'); });
        $service->request('user@example.org');
        $service->request('unknown@example.org');
        $service->request('inactive@example.org');
        $this->assertSame(3, (int) $this->db->query('SELECT count(*) FROM password_reset_requests')->fetchColumn());
        $this->assertSame(0, (int) $this->db->query('SELECT count(*) FROM password_resets')->fetchColumn());
        // Eine Kontosperre darf keine der oeffentlichen Anfragen blockieren.
        $locker = $this->connect();
        $locker->beginTransaction();
        $locker->query('SELECT * FROM users FOR UPDATE');
        try {
            $this->db->exec("SET lock_timeout = '100ms'");
            $service->request('another@example.org');
            $this->assertSame(4, (int) $this->db->query('SELECT count(*) FROM password_reset_requests')->fetchColumn());
        } finally { $locker->rollBack(); }
    }

    public function test_smtp_holds_no_queue_lock_and_concurrent_worker_cannot_send_twice(): void
    {
        $calls = 0;
        $other = $this->connect();
        $other->exec("SET lock_timeout = '100ms'");
        $second = $this->service($other, static function (): void { throw new \LogicException('Doppelte Zustellung'); });
        $first = $this->service($this->db, function (PHPMailer $mail) use ($second, &$calls): void {
            $calls++;
            $this->assertFalse($this->db->inTransaction());
            $this->assertSame(0, $second->processQueue());
            $second->request('another@example.org');
        });
        $first->request('user@example.org');
        $this->assertSame(1, $first->processQueue(1));
        $this->assertSame(1, $calls);
        $this->assertSame(1, (int) $other->query('SELECT count(*) FROM password_reset_requests')->fetchColumn());
    }

    public function test_failure_and_expired_requests_leave_no_usable_token(): void
    {
        $service = $this->service($this->db, static function (): void { throw new \RuntimeException('test failure'); });
        $service->request('user@example.org');
        $this->assertSame(1, $service->processQueue());
        $this->assertSame(0, (int) $this->db->query('SELECT count(*) FROM password_resets')->fetchColumn());
        $this->db->exec("INSERT INTO password_reset_requests (email, requested_at) VALUES ('user@example.org', now() - interval '16 minutes')");
        $this->assertSame(0, $service->processQueue());
    }
}
