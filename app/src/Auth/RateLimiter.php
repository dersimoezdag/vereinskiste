<?php
declare(strict_types=1);

namespace Vereinskiste\Auth;

use Closure;
use RuntimeException;

/** Gemeinsame atomare Budgets, auch vor DB-Migrationen verfuegbar. Keine Klardaten. */
final class RateLimiter
{
    public function __construct(private string $directory, private ?Closure $clock = null)
    {
    }

    public function take(string $operation, string $email, string $address): bool
    {
        $limits = match ($operation) {
            'login' => [5, 60, 120],
            'reset' => [1, 5, 10],
            'setup' => [5, 10, 30],
            default => throw new \InvalidArgumentException('Unbekanntes Versuchsbudget.'),
        };
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Sicherheitsbudget nicht verfügbar.');
        }
        $path = $this->directory . '/rate-limits.json';
        $file = fopen($path, 'c+');
        if ($file === false || !flock($file, LOCK_EX)) {
            if (is_resource($file)) { fclose($file); }
            throw new RuntimeException('Sicherheitsbudget nicht verfügbar.');
        }
        try {
            chmod($path, 0600);
            $content = stream_get_contents($file, 1048577);
            if ($content === false || strlen($content) > 1048576) {
                throw new RuntimeException('Sicherheitsbudget unlesbar.');
            }
            $state = $content === '' ? ['salt' => bin2hex(random_bytes(32)), 'buckets' => []]
                : json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($state) || !is_string($state['salt'] ?? null) || !is_array($state['buckets'] ?? null)) {
                throw new RuntimeException('Sicherheitsbudget unlesbar.');
            }
            $now = $this->clock !== null ? ($this->clock)() : time();
            $buckets = array_filter($state['buckets'], static fn (array $bucket): bool => $bucket['until'] > $now);
            $subjects = [UserRepository::normalizeEmail($email), self::address($address), '*'];
            $keys = [];
            foreach ($subjects as $i => $subject) {
                $key = hash_hmac('sha256', $operation . ':' . $i . ':' . $subject, $state['salt']);
                if (($buckets[$key]['count'] ?? 0) >= $limits[$i]) {
                    return false;
                }
                $keys[] = $key;
            }
            foreach ($keys as $key) {
                $buckets[$key] ??= ['count' => 0, 'until' => $now + 60];
                $buckets[$key]['count']++;
            }
            $encoded = json_encode(['salt' => $state['salt'], 'buckets' => $buckets], JSON_THROW_ON_ERROR);
            rewind($file);
            if (!ftruncate($file, 0) || fwrite($file, $encoded) !== strlen($encoded) || !fflush($file)) {
                throw new RuntimeException('Sicherheitsbudget nicht speicherbar.');
            }
            return true;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }

    /** Nur serverseitiges REMOTE_ADDR; auch IPv4-mapped IPv6 wird kanonisiert. */
    public static function address(string $address): string
    {
        $packed = @inet_pton($address);
        if ($packed === false) { return 'unknown'; }
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $packed = substr($packed, 12);
        }
        return (string) inet_ntop($packed);
    }
}
