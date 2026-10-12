<?php
declare(strict_types=1);

namespace Vereinskiste\Db;

use PDO;
use RuntimeException;
use Throwable;
use Vereinskiste\Ledger\Transaction;

final class Migrator
{
    public function __construct(private PDO $pdo, private string $dir)
    {
    }

    /** @return string[] Namen der angewendeten Migrationen, in Reihenfolge */
    public function migrate(): array
    {
        if ($this->pdo->inTransaction()) {
            return $this->apply();
        }
        // Die Versionstabelle bleibt fuer Diagnose und vorhandene Aufrufer erhalten.
        $this->ensureTable();
        return Transaction::run($this->pdo, fn (): array => $this->apply());
    }

    private function apply(): array
    {
        $this->pdo->query('SELECT pg_advisory_xact_lock(7340164)');
        $applied = [];
        foreach ($this->pending() as $name) {
            $sql = file_get_contents($this->dir . '/' . $name);
            if ($sql === false) {
                throw new RuntimeException("Migration nicht lesbar: $name");
            }
            try {
                $this->pdo->exec($sql);
                $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (name, applied_at) VALUES (?, now())');
                $stmt->execute([$name]);
            } catch (Throwable $e) {
                throw new RuntimeException("Migration $name fehlgeschlagen: " . $e->getMessage(), 0, $e);
            }
            $applied[] = $name;
        }
        return $applied;
    }

    /** @return string[] noch nicht angewendete Migrationsdateien, sortiert */
    public function pending(): array
    {
        $this->ensureTable();
        $done = $this->pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = [];
        foreach (scandir($this->dir) ?: [] as $f) {
            if (preg_match('/^\d{4}_[A-Za-z0-9_]+\.sql$/', $f)) {
                $files[] = $f;
            }
        }
        sort($files, SORT_STRING);
        return array_values(array_diff($files, $done));
    }

    public function currentVersion(): ?string
    {
        $this->ensureTable();
        $v = $this->pdo->query('SELECT name FROM schema_migrations ORDER BY name DESC LIMIT 1')->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (name text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now())'
        );
    }
}
