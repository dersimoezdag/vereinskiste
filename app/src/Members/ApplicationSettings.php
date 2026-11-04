<?php
declare(strict_types=1);

namespace Vereinskiste\Members;

use PDO;
use Vereinskiste\Audit\AuditLog;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Ledger\Transaction;

final class ApplicationSettings
{
    public function __construct(private PDO $pdo) {}

    public function get(): array
    {
        $row = $this->pdo->query('SELECT require_sepa, version FROM application_settings WHERE id=1')->fetch();
        return ['require_sepa' => (bool) $row['require_sepa'], 'version' => (int) $row['version']];
    }

    public function save(bool $requireSepa, int $version, int $actor): void
    {
        Transaction::run($this->pdo, function () use ($requireSepa, $version, $actor): void {
            $stmt = $this->pdo->prepare('UPDATE application_settings SET require_sepa=?::boolean, version=version+1 WHERE id=1 AND version=?');
            $stmt->execute([$requireSepa ? 'true' : 'false', $version]);
            if ($stmt->rowCount() !== 1) { throw new Conflict(); }
            (new AuditLog($this->pdo))->record('application_settings_updated', $actor);
        });
    }
}
