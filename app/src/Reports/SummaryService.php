<?php
declare(strict_types=1);

namespace Vereinskiste\Reports;

use PDO;
use Vereinskiste\Ledger\AccountRepository;
use Vereinskiste\Ledger\DateInput;
use Vereinskiste\Ledger\ValidationError;

/**
 * Auswertung eines Zeitraums, vollstaendig in der Datenbank aggregiert (Betraege exakt als Strings).
 *
 * Es zaehlen nur aktive Buchungen; Umbuchungen sind ausgeschlossen (kein Vereinsvermoegen aendert sich),
 * Korrekturbuchungen zaehlen entsprechend ihrer Richtung als Einnahme bzw. Ausgabe.
 */
final class SummaryService
{
    /** Name der Zeile fuer Buchungen ohne Kategorie. */
    public const UNCATEGORIZED = 'Ohne Kategorie';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param string $from erster Tag (inklusive, YYYY-MM-DD)
     * @param string $to letzter Tag (inklusive, YYYY-MM-DD)
     * @return array{
     *     from:string, to:string, account_id:?int, account_name:?string,
     *     income:string, expense:string, balance:string, accountBalanceAtEnd:?string,
     *     byCategory:list<array{category_id:?int,name:string,income:string,expense:string}>,
     *     corrections:list<array<string,mixed>>
     * }
     * @throws ValidationError bei ungueltigem oder vertauschtem Zeitraum
     */
    public function summary(string $from, string $to, ?int $accountId = null): array
    {
        $errors = [];
        foreach (['from' => $from, 'to' => $to] as $field => $value) {
            if (!DateInput::valid($value)) {
                $errors[$field] = 'validation.date.invalid';
            }
        }
        if ($errors === [] && $from > $to) {
            $errors['to'] = 'validation.range.swapped';
        }
        $accountName = null;
        if ($accountId !== null) {
            $account = (new AccountRepository($this->pdo))->find($accountId);
            if ($account === null) {
                $errors['account_id'] = 'validation.booking.account';
            } else {
                $accountName = $account['name'];
            }
        }
        if ($errors !== []) {
            throw new ValidationError($errors);
        }

        $params = ['from' => $from, 'to' => $to];
        $accountCond = '';
        if ($accountId !== null) {
            $accountCond = ' AND b.account_id = :account_id';
            $params['account_id'] = $accountId;
        }

        $rows=(new BookingAggregation($this->pdo))->categories($params,$accountCond);
        $income = '0.00';
        $expense = '0.00';
        $balance = '0.00';
        $byCategory = [];
        foreach ($rows as $row) {
            if ((int) $row['is_total'] === 3) {
                $income = $row['income'];
                $expense = $row['expense'];
                $balance = $row['balance'];
                continue;
            }
            $byCategory[] = [
                'category_id' => $row['category_id'] === null ? null : (int) $row['category_id'],
                'name' => $row['name'] ?? self::UNCATEGORIZED,
                'income' => $row['income'],
                'expense' => $row['expense'],
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'account_id' => $accountId,
            'account_name' => $accountName,
            'income' => $income,
            'expense' => $expense,
            'balance' => $balance,
            'accountBalanceAtEnd' => $accountId === null ? null : (new AccountRepository($this->pdo))->balance($accountId, $to),
            'byCategory' => $byCategory,
            'corrections' => $this->corrections($params, $accountCond),
        ];
    }

    /**
     * Alle Korrekturbuchungen des Zeitraums (auch stornierte), damit Pruefer sie sehen.
     *
     * @param array<string,string|int> $params
     * @return list<array<string,mixed>>
     */
    private function corrections(array $params, string $accountCond): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT b.id, b.booking_date::text AS booking_date, b.amount::text AS amount, b.direction,
                    b.description, b.status, a.name AS account_name, c.name AS category_name
               FROM bookings b
               JOIN accounts a ON a.id = b.account_id
               LEFT JOIN categories c ON c.id = b.category_id
              WHERE b.kind = 'correction'
                AND b.booking_date >= CAST(:from AS date) AND b.booking_date <= CAST(:to AS date)$accountCond
              ORDER BY b.booking_date, b.id"
        );
        $stmt->execute($params);
        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            return $row;
        }, $stmt->fetchAll());
    }
}
