<?php
declare(strict_types=1);

namespace Vereinskiste\Jobs;

use Throwable;

/** Handler muessen ihre Geschaeftswirkung selbst idempotent absichern. */
final class Runner
{
    /** @param array<string,\Closure(array):void> $handlers */
    public function __construct(private Queue $queue, private array $handlers) {}

    public function run(int $seconds = 20, int $limit = 10): int
    {
        $deadline = microtime(true) + max(1, min(20, $seconds)); $count = 0;
        while ($count < max(0, min(100, $limit)) && microtime(true) < $deadline) {
            $job = $this->queue->claim();
            if ($job === null) { break; }
            $count++;
            if (!isset($this->handlers[$job['kind']])) {
                $this->queue->complete($job, false, 'unsupported_kind');
                continue;
            }
            try {
                ($this->handlers[$job['kind']])($job['payload']);
                $this->queue->complete($job, true);
            } catch (Throwable) {
                // Keine Transportdetails/Payloads im technischen Log.
                $this->queue->complete($job, false);
            }
        }
        $this->queue->prune();
        return $count;
    }
}
