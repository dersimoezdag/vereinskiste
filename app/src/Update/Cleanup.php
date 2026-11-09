<?php
declare(strict_types=1);
namespace Vereinskiste\Update;

/**
 * Removes leftovers of finished auto-updates from storage/updates (daily, via the mail cron).
 *
 * Runs only while no update is in progress (terminal phase, no maintenance freeze) and under the
 * worker lock, so it can never race a Coordinator step. Never touches the active release, the
 * release that was active before the last update, or the record of the last operation.
 *  - package.zip of finished operations: removed (extracted and verified long before)
 *  - database exports of the last operation: removed after RETENTION seconds
 *  - older operations: removed completely after RETENTION seconds
 *  - releases other than active/previous: removed
 */
final class Cleanup
{
    public const RETENTION = 7 * 86400;
    public function __construct(private Runtime $runtime, private ?int $now = null) {}
    /** @return int number of removed entries */
    public function run(): int
    {
        $state = new State($this->runtime);
        try {
            return $state->locked(function () use ($state): int {
                if ($this->runtime->maintenance() !== null) { return 0; }
                $current = $state->load();
                if ($current !== null && !in_array($current['phase'] ?? '', Coordinator::TERMINAL, true)) { return 0; }
                $keep = array_filter([$this->runtime->active()['id'] ?? null, $current['previous']['id'] ?? null]);
                $removed = 0; $cutoff = ($this->now ?? time()) - self::RETENTION;
                foreach ($this->ids('releases') as $id) {
                    if (!in_array($id, $keep, true)) { Files::remove($this->path('releases', $id)); $removed++; }
                }
                foreach ($this->ids('operations') as $id) {
                    $dir = $this->path('operations', $id);
                    if ($id === ($current['id'] ?? null)) {
                        if (is_file($dir . '/package.zip')) { Files::remove($dir . '/package.zip'); $removed++; }
                        if (is_dir($dir . '/backup') && $this->changedAt($dir) < $cutoff) { Files::remove($dir . '/backup'); $removed++; }
                    } elseif ($this->changedAt($dir) < $cutoff) { Files::remove($dir); $removed++; }
                    elseif (is_file($dir . '/package.zip')) { Files::remove($dir . '/package.zip'); $removed++; }
                }
                return $removed;
            });
        } catch (Failure $busy) {
            if ($busy->reason === 'busy') { return 0; }
            throw $busy;
        }
    }
    /** @return list<string> */
    private function ids(string $kind): array
    {
        $dir = $this->runtime->directory() . '/' . $kind;
        if (!is_dir($dir) || is_link($dir)) { return []; }
        return array_values(array_filter(scandir($dir) ?: [], static fn (string $name): bool => (bool) preg_match('/^[a-f0-9]{32}\z/', $name)));
    }
    private function path(string $kind, string $id): string { return $this->runtime->directory() . '/' . $kind . '/' . $id; }
    /** Last state change of an operation (state.json is rewritten on every phase change). */
    private function changedAt(string $dir): int
    {
        $time = is_file($dir . '/state.json') ? filemtime($dir . '/state.json') : filemtime($dir);
        return $time === false ? PHP_INT_MAX : $time;
    }
}
