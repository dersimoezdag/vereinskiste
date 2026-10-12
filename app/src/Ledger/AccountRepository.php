<?php
declare(strict_types=1);

namespace Vereinskiste\Ledger;

use InvalidArgumentException;
use PDO;
use PDOException;
use Vereinskiste\Audit\AuditLog;

final class AccountRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array{id:int,name:string,type:string,opening_balance:string,opening_date:string,active:bool}> */
    public function all(): array
    {
        return $this->pdo->query(
            'SELECT id, name, type, opening_balance::text AS opening_balance, opening_date::text AS opening_date, active
               FROM accounts ORDER BY active DESC, lower(name), id'
        )->fetchAll();
    }

    /** @return array{id:int,name:string,type:string,opening_balance:string,opening_date:string,active:bool}|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, type, opening_balance::text AS opening_balance, opening_date::text AS opening_date, active
               FROM accounts WHERE id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM accounts WHERE lower(name) = lower(?) AND id <> ?');
        $stmt->execute([$name, $exceptId ?? 0]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Legt ein Konto an und protokolliert `account_created` in derselben Transaktion.
     *
     * @throws NameAlreadyExists
     */
    public function create(string $name, string $type, string $openingBalance, string $openingDate, int $userId): int
    {
        try {
            return Transaction::run($this->pdo, function () use ($name, $type, $openingBalance, $openingDate, $userId): int {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO accounts (name, type, opening_balance, opening_date) VALUES (?, ?, ?, ?) RETURNING id'
                );
                $stmt->execute([$name, $type, $openingBalance, $openingDate]);
                $id = (int) $stmt->fetchColumn();
                (new AuditLog($this->pdo))->record('account_created', $userId, ['id' => $id, 'name' => $name]);
                return $id;
            });
        } catch (PDOException $e) {
            throw NameAlreadyExists::from($e);
        }
    }

    /**
     * Benennt um und protokolliert `account_renamed` (id, from, to); unveraenderter Name schreibt nichts.
     *
     * @throws NameAlreadyExists
     * @throws InvalidArgumentException unbekanntes Konto
     */
    public function rename(int $id, string $name, int $userId): void
    {
        try {
            Transaction::run($this->pdo, function () use ($id, $name, $userId): void {
                $stmt = $this->pdo->prepare('SELECT name FROM accounts WHERE id = ? FOR UPDATE');
                $stmt->execute([$id]);
                $old = $stmt->fetchColumn();
                if ($old === false) {
                    throw new InvalidArgumentException("Unbekanntes Konto $id.");
                }
                if ($old === $name) {
                    return;
                }
                $this->pdo->prepare('UPDATE accounts SET name = ? WHERE id = ?')->execute([$name, $id]);
                (new AuditLog($this->pdo))->record('account_renamed', $userId, ['id' => $id, 'from' => $old, 'to' => $name]);
            });
        } catch (PDOException $e) {
            throw NameAlreadyExists::from($e);
        }
    }

    /**
     * Schaltet aktiv/inaktiv um und protokolliert `account_activated`/`account_deactivated`.
     *
     * @return bool neuer Zustand (true = aktiv)
     * @throws InvalidArgumentException unbekanntes Konto
     */
    public function toggleActive(int $id, int $userId): bool
    {
        return Transaction::run($this->pdo, function () use ($id, $userId): bool {
            $stmt = $this->pdo->prepare('UPDATE accounts SET active = NOT active WHERE id = ? RETURNING active, name');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw new InvalidArgumentException("Unbekanntes Konto $id.");
            }
            (new AuditLog($this->pdo))->record(
                $row['active'] ? 'account_activated' : 'account_deactivated',
                $userId,
                ['id' => $id, 'name' => $row['name']]
            );
            return (bool) $row['active'];
        });
    }

    /**
     * Saldo = Anfangssaldo + Einnahmen - Ausgaben ueber alle aktiven Buchungen
     * (Umbuchungen zaehlen mit, stornierte nicht). Es zaehlen nur Buchungen ab
     * dem Eroeffnungsdatum des Kontos (der Anfangssaldo gilt zu diesem Tag).
     * Der Filter `booking_date >= opening_date` bleibt als Schutz erhalten: BookingService und
     * TransferService lehnen Buchungen davor ab; solche Zeilen koennen
     * nur noch aus aelteren Daten stammen (neue Daten enthalten sie nicht mehr).
     * `$asOf` (YYYY-MM-DD) ist inklusive; davor liegende Stichtage ergeben den Anfangssaldo.
     *
     * @return string Betrag mit zwei Nachkommastellen, z. B. "125.15"
     */
    public function balance(int $accountId, ?string $asOf = null): string
    {
        $stmt = $this->pdo->prepare(
            "SELECT (a.opening_balance + COALESCE(SUM(CASE WHEN b.direction = 'in' THEN b.amount ELSE -b.amount END), 0))::text
               FROM accounts a
               LEFT JOIN bookings b
                      ON b.account_id = a.id
                     AND b.status = 'active'
                     AND b.booking_date >= a.opening_date
                     AND (CAST(:as_of_null AS date) IS NULL OR b.booking_date <= CAST(:as_of AS date))
              WHERE a.id = :id
              GROUP BY a.id"
        );
        $stmt->execute(['as_of_null' => $asOf, 'as_of' => $asOf, 'id' => $accountId]);
        $balance = $stmt->fetchColumn();
        if ($balance === false) {
            throw new InvalidArgumentException("Unbekanntes Konto $accountId.");
        }
        return $balance;
    }
}
