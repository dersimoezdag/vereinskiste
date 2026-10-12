<?php
declare(strict_types=1);

namespace Vereinskiste\Ledger;

use PDO;
use Throwable;

/**
 * Fuehrt `$work` in einer Transaktion aus; laeuft bereits eine (Aufrufer besitzt sie), wird diese genutzt.
 *
 * Vertrag: Wirft `$work` in einer vom Aufrufer besessenen Transaktion eine Exception, rollt `run` sie nicht
 * zurueck (die Transaktion gehoert dem Aufrufer). Aufrufer, die eine innerhalb geworfene Exception faengen,
 * muessen die aeussere Transaktion zurueckrollen: schon geschriebene Teile blieben sonst im spaeteren Commit.
 */
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
