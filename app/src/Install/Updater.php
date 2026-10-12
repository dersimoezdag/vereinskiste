<?php
declare(strict_types=1);

namespace Vereinskiste\Install;

use PDO;
use RuntimeException;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\Transaction;

final class Updater
{
    public function __construct(private PDO $pdo, private string $migrationDir)
    {
    }

    public function needsUpdate(): bool
    {
        return !$this->isCompatible() || (new Migrator($this->pdo, $this->migrationDir))->pending() !== [];
    }

    /** Ein neuerer Datenbankstand darf nicht mit einem alten Release weiterlaufen. */
    public function isCompatible(): bool
    {
        $current = (new Migrator($this->pdo, $this->migrationDir))->currentVersion();
        return $current === null || is_file($this->migrationDir . '/' . $current);
    }

    /** Alle ausstehenden Schritte werden gemeinsam committed oder zurueckgerollt. @return list<string> */
    public function run(bool $backupConfirmed): array
    {
        if (!$backupConfirmed) {
            throw new RuntimeException('Bitte bestätige zuerst ein aktuelles Backup von Datenbank und Belegen.');
        }
        if (!$this->isCompatible()) {
            throw new RuntimeException('Die Datenbank passt nicht zu diesem Release. Bitte lade das passende Release hoch.');
        }
        return Transaction::run($this->pdo, fn (): array =>
            (new Migrator($this->pdo, $this->migrationDir))->migrate());
    }
}
