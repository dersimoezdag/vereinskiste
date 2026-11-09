<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Jobs;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Jobs\Queue;
use Vereinskiste\Jobs\Runner;
use Vereinskiste\Tests\TestCase;

final class QueueTest extends TestCase
{
    private PDO $db;
    protected function setUp(): void
    {
        $this->db = $this->pdo();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();
    }

    public function test_duplicate_enqueue_claim_leases_and_fencing(): void
    {
        $queue = new Queue($this->db); $other = new Queue($this->connect());
        $queue->enqueue('test', [], 'same'); $other->enqueue('test', [], 'same');
        $job = $queue->claim(); $this->assertNotNull($job); $this->assertNull($other->claim());
        $this->assertFalse($this->db->inTransaction());
        $this->db->exec("UPDATE jobs SET lease_until=now()-interval '1 second'");
        $next = $other->claim(); $this->assertNotSame($job['lease_token'], $next['lease_token']);
        $this->assertFalse($queue->complete($job, true));
        $this->assertTrue($other->complete($next, true));
        $this->assertNull($queue->claim());
    }

    public function test_retry_limit_and_safe_errors_and_priority(): void
    {
        $queue = new Queue($this->db); $queue->enqueue('slow', [], 'a'); $queue->enqueue('fast', [], 'b', 100);
        $this->assertSame('fast', $queue->claim()['kind']);
        $calls = 0;
        $runner = new Runner($queue, ['slow' => function () use (&$calls): void { $calls++; throw new \RuntimeException('secret'); }]);
        for ($i = 0; $i < 3; $i++) {
            $this->db->exec("UPDATE jobs SET available_at=now() WHERE kind='slow'");
            $runner->run(1, 1);
        }
        $this->assertSame(3, $calls);
        $row = $this->db->query("SELECT status,error_code FROM jobs WHERE kind='slow'")->fetch();
        $this->assertSame(['status' => 'failed', 'error_code' => 'handler_failed'], $row);
        $this->assertNull($queue->claim());
    }
    public function test_prune_drops_done_jobs_after_a_day_and_failed_jobs_after_thirty_days(): void
    {
        $queue = new Queue($this->db);
        foreach (['done_old' => ['done', '25 hours'], 'done_new' => ['done', '23 hours'], 'failed_mid' => ['failed', '10 days'],
                  'failed_old' => ['failed', '31 days'], 'pending' => ['pending', null]] as $kind => [$status, $age]) {
            $queue->enqueue(str_replace('_', '-', $kind), [], $kind);
            $this->db->prepare("UPDATE jobs SET status=?, finished_at=CASE WHEN ?::text IS NULL THEN NULL ELSE now()-?::interval END WHERE kind=?")
                ->execute([$status, $age, $age, str_replace('_', '-', $kind)]);
        }
        $queue->prune();
        $this->assertSame(['done-new', 'failed-mid', 'pending'], $this->db->query('SELECT kind FROM jobs ORDER BY kind')->fetchAll(\PDO::FETCH_COLUMN));
    }
}
