<?php
declare(strict_types=1);

namespace Vereinskiste\Ledger;

use PDO;
use Throwable;

/** Fuehrt `$work` in einer Transaktion aus; laeuft bereits eine (Aufrufer besitzt sie), wird diese genutzt. */
final class Transaction
{
    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public static function run(PDO $pdo, callable $work): mixed
    {
        if ($pdo->inTransaction()) {
            return $work();
        }
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
