<?php
declare(strict_types=1);

namespace Vereinskiste\Reports;

use PDO;
use Vereinskiste\Ledger\AccountRepository;
use Vereinskiste\Ledger\Money;

/** Kontostaende und Kassenbuch mit laufendem Saldo fuer die Uebersicht der Vereinskasse. */
final class CashOverview
{
    public const LEDGER_ROWS = 25;

    public function __construct(private PDO $pdo) {}

    /** @return array{accounts:list<array<string,mixed>>,total:string} */
    public function balances(): array
    {
        $repo = new AccountRepository($this->pdo);
        $accounts = [];
        $total = 0;
        foreach ($repo->all() as $account) {
            $account['balance'] = $repo->balance((int) $account['id']);
            $total += Money::toCents($account['balance'], true);
            $accounts[] = $account;
        }
        return ['accounts' => $accounts, 'total' => Money::fromCents($total)];
    }

    /**
     * Letzte Buchungen, neueste zuerst, mit dem Saldo nach der jeweiligen Buchung wie im Kassenbuch.
     * Es gelten dieselben Regeln wie bei AccountRepository::balance, der oberste Saldo ist der aktuelle Stand.
     *
     * @return list<array<string,mixed>>
     */
    public function ledger(?int $accountId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM (
                SELECT b.id, b.booking_date::text AS booking_date, b.description, b.counterparty, b.direction, b.amount::text AS amount,
                       b.kind, a.name AS account_name,
                       ((SELECT COALESCE(sum(opening_balance), 0) FROM accounts WHERE CAST(:opening AS bigint) IS NULL OR id = CAST(:opening AS bigint))
                        + sum(CASE WHEN b.direction = 'in' THEN b.amount ELSE -b.amount END) OVER (ORDER BY b.booking_date, b.id))::text AS running
                  FROM bookings b JOIN accounts a ON a.id = b.account_id
                 WHERE b.status = 'active' AND b.booking_date >= a.opening_date
                   AND (CAST(:filter AS bigint) IS NULL OR b.account_id = CAST(:filter AS bigint))
             ) rows ORDER BY booking_date DESC, id DESC LIMIT " . self::LEDGER_ROWS
        );
        $stmt->execute(['opening' => $accountId, 'filter' => $accountId]);
        return $stmt->fetchAll();
    }
}
