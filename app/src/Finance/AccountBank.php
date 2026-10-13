<?php
declare(strict_types=1);

namespace Vereinskiste\Finance;

use PDO;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\History;
use Vereinskiste\Ledger\Transaction;

final class AccountBank
{
    public function __construct(private PDO $pdo) {}

    public function get(int $id, bool $lock = false): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, type, iban, bic, bank_version FROM accounts WHERE id = ?'
            . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function save(int $id, string $iban, string $bic, int $version, int $actor, string $reason): void
    {
        $data = BankDetails::read($iban, $bic);
        Transaction::run($this->pdo, function () use ($id, $data, $version, $actor, $reason): void {
            $before = $this->get($id, true);
            if ($before === null || (int) $before['bank_version'] !== $version) { throw new Conflict(); }
            (new History($this->pdo))->record('account_bank', $id,
                ['iban' => $before['iban'], 'bic' => $before['bic']], $data, $actor, $reason);
            if ($before['iban'] === $data['iban'] && $before['bic'] === $data['bic']) { return; }
            $this->pdo->prepare('UPDATE accounts SET iban=?,bic=?,bank_version=bank_version+1 WHERE id=?')
                ->execute([$data['iban'], $data['bic'], $id]);
        });
    }
}
