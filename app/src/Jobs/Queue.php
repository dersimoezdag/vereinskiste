<?php
declare(strict_types=1);

namespace Vereinskiste\Jobs;

use PDO;
use RuntimeException;

final class Queue
{
    public function __construct(private PDO $pdo) {}

    public function enqueue(string $kind, array $payload, string $key, int $priority = 0): void
    {
        if (!preg_match('/^[a-z0-9_.-]{1,64}\z/', $kind)) { throw new \InvalidArgumentException('Jobtyp ungültig.'); }
        $encoded = json_encode((object) $payload, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 8192) { throw new \InvalidArgumentException('Jobdaten zu groß.'); }
        $this->pdo->prepare('INSERT INTO jobs (kind,payload,dedup_key,priority) VALUES (?,?::jsonb,?,?)
            ON CONFLICT (dedup_key) DO NOTHING')->execute([$kind, $encoded, hash('sha256', $kind . ':' . $key), $priority]);
    }

    public function claim(): ?array
    {
        if ($this->pdo->inTransaction()) { throw new RuntimeException('Jobannahme benötigt Autocommit.'); }
        $this->pdo->exec("UPDATE jobs SET status='failed',lease_token=NULL,lease_until=NULL,error_code='attempt_limit',finished_at=now()
            WHERE attempts >= 3 AND (status='pending' OR status='running' AND lease_until < now())");
        $token = bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare("UPDATE jobs SET status='running',attempts=attempts+1,
            lease_until=now()+interval '2 minutes',lease_token=?,error_code=NULL
            WHERE id=(SELECT id FROM jobs WHERE attempts < 3 AND
              (status='pending' AND available_at<=now() OR status='running' AND lease_until<now())
              ORDER BY priority DESC,available_at,id FOR UPDATE SKIP LOCKED LIMIT 1) RETURNING *");
        $stmt->execute([$token]);
        $job = $stmt->fetch();
        if ($job === false) { return null; }
        $job['payload'] = json_decode($job['payload'], true, 512, JSON_THROW_ON_ERROR);
        return $job;
    }

    public function complete(array $job, bool $success, string $error = 'handler_failed'): bool
    {
        $stmt = $this->pdo->prepare("UPDATE jobs SET status=CASE WHEN ?::boolean THEN 'done'
                WHEN attempts>=3 THEN 'failed' ELSE 'pending' END,
            progress=CASE WHEN ?::boolean THEN 100 ELSE progress END,
            available_at=now()+make_interval(secs => 30 * attempts * attempts),
            finished_at=CASE WHEN ?::boolean OR attempts>=3 THEN now() ELSE NULL END,
            error_code=CASE WHEN ?::boolean THEN NULL ELSE ? END,lease_until=NULL,lease_token=NULL
            WHERE id=? AND status='running' AND lease_token=? AND lease_until>now()");
        $flag = $success ? 'true' : 'false';
        $stmt->execute([$flag, $flag, $flag, $flag, $error, $job['id'], $job['lease_token']]);
        return $stmt->rowCount() === 1;
    }

    public function summary(): array
    {
        return $this->pdo->query('SELECT status,count(*)::int AS count FROM jobs GROUP BY status ORDER BY status')->fetchAll();
    }

    public function prune(): void
    {
        $this->pdo->exec("DELETE FROM jobs WHERE id IN (SELECT id FROM jobs
            WHERE status='done' AND finished_at < now()-interval '30 days' ORDER BY id LIMIT 100)");
    }
}
