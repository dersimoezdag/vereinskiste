<?php
declare(strict_types=1);

namespace Vereinskiste\Ledger;

use PDO;

/**
 * Umbuchung zwischen zwei eigenen Konten: zwei verknuepfte Buchungen (Ausgabe auf dem Quell-,
 * Einnahme auf dem Zielkonto) mit gleicher transfer_id, ohne Kategorie. Beide Buchungen werden
 * immer gemeinsam angelegt, storniert und wiederhergestellt; Feldaenderungen gibt es nicht
 * (falsche Umbuchung: stornieren und neu anlegen).
 */
final class TransferService
{
    private ClosingService $closing;

    public function __construct(private PDO $pdo)
    {
        $this->closing = new ClosingService($pdo);
    }

    /**
     * @return list<array<string,mixed>> [Ausgabe auf dem Quellkonto, Einnahme auf dem Zielkonto]
     * @throws ValidationError Schluessel from_account_id, to_account_id, amount, booking_date, description
     * @throws ClosedPeriod wenn das Datum im abgeschlossenen Zeitraum liegt
     */
    public function create(
        int $fromAccountId,
        int $toAccountId,
        string $amount,
        string $date,
        string $description,
        int $userId
    ): array {
        $errors = [];
        $accounts = new AccountRepository($this->pdo);
        foreach (['from_account_id' => $fromAccountId, 'to_account_id' => $toAccountId] as $key => $accountId) {
            $account = $accountId > 0 ? $accounts->find($accountId) : null;
            if ($account === null || !$account['active']) {
                $errors[$key] = 'validation.booking.account';
            }
        }
        if ($errors === [] && $fromAccountId === $toAccountId) {
            $errors['to_account_id'] = 'validation.transfer.same_account';
        }

        $canonical = null;
        try {
            $canonical = Money::parse($amount);
        } catch (InvalidAmount) {
            $errors['amount'] = 'validation.booking.amount';
        }
        if (!DateInput::valid($date)) {
            $errors['booking_date'] = 'validation.date.invalid';
        }
        $description = trim($description);
        $error = BookingService::textError(
            $description,
            BookingService::DESCRIPTION_MAX,
            'validation.booking.description.too_long',
            'validation.booking.description.required'
        );
        if ($error !== null) {
            $errors['description'] = $error;
        }
        if ($errors !== []) {
            throw new ValidationError($errors);
        }

        return Transaction::run($this->pdo, function () use ($fromAccountId, $toAccountId, $canonical, $date, $description, $userId): array {
            $this->closing->assertOpen($date);
            $transferId = (int) $this->pdo->query("SELECT nextval('booking_transfer_id_seq')")->fetchColumn();
            $insert = $this->pdo->prepare(
                "INSERT INTO bookings (account_id, booking_date, amount, direction, category_id, description,
                                       kind, status, transfer_id, version, created_by)
                 VALUES (?, ?, ?, ?, NULL, ?, 'transfer', 'active', ?, 1, ?)"
            );
            foreach ([[$fromAccountId, 'out'], [$toAccountId, 'in']] as [$accountId, $direction]) {
                $insert->execute([$accountId, $date, $canonical, $direction, $description, $transferId, $userId]);
            }
            return $this->legs($transferId);
        });
    }

    /**
     * Beide Buchungen einer Umbuchung, Ausgabe zuerst.
     *
     * @return list<array<string,mixed>>
     */
    public function legs(int $transferId): array
    {
        $repo = new BookingRepository($this->pdo);
        $stmt = $this->pdo->prepare(
            "SELECT id FROM bookings WHERE transfer_id = ? ORDER BY CASE direction WHEN 'out' THEN 0 ELSE 1 END, id"
        );
        $stmt->execute([$transferId]);
        $legs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $legs[] = $repo->find((int) $id);
        }
        /** @var list<array<string,mixed>> */
        return $legs;
    }

    /**
     * Storniert beide Buchungen der Umbuchung, zu der `$bookingId` gehoert.
     * `$expectedVersion` ist die Version der Buchung, die der Benutzer vor sich hatte.
     *
     * @return list<array<string,mixed>> beide Buchungen danach
     * @throws ValidationError|VersionConflict|BookingNotFound|BookingLocked|ClosedPeriod
     */
    public function cancel(int $bookingId, string $reason, int $expectedVersion, int $userId): array
    {
        return $this->changeStatus($bookingId, 'active', 'cancelled', $reason, $expectedVersion, $userId);
    }

    /**
     * Nimmt das Storno beider Buchungen zurueck.
     *
     * @return list<array<string,mixed>>
     * @throws ValidationError|VersionConflict|BookingNotFound|BookingLocked|ClosedPeriod
     */
    public function restore(int $bookingId, string $reason, int $expectedVersion, int $userId): array
    {
        return $this->changeStatus($bookingId, 'cancelled', 'active', $reason, $expectedVersion, $userId);
    }

    /** @return list<array<string,mixed>> */
    private function changeStatus(int $bookingId, string $from, string $to, string $reason, int $expectedVersion, int $userId): array
    {
        $reason = ChangeLog::normalizeReason($reason);
        return Transaction::run($this->pdo, function () use ($bookingId, $from, $to, $reason, $expectedVersion, $userId): array {
            $stmt = $this->pdo->prepare('SELECT kind, transfer_id FROM bookings WHERE id = ?');
            $stmt->execute([$bookingId]);
            $booking = $stmt->fetch();
            if ($booking === false) {
                throw new BookingNotFound($bookingId);
            }
            if ($booking['kind'] !== 'transfer' || $booking['transfer_id'] === null) {
                throw new BookingLocked($bookingId, 'bookings.locked.not_transfer');
            }

            // Beide Buchungen in fester Reihenfolge sperren (keine Verklemmung bei gleichzeitigen Aufrufen).
            $stmt = $this->pdo->prepare(
                'SELECT id, version, status, booking_date::text AS booking_date
                   FROM bookings WHERE transfer_id = ? ORDER BY id FOR UPDATE'
            );
            $stmt->execute([(int) $booking['transfer_id']]);
            $legs = $stmt->fetchAll();
            // Eine Umbuchung besteht immer aus genau zwei Buchungen; alles andere ist ein Datenfehler.
            if (count($legs) !== 2) {
                throw new BookingLocked($bookingId, 'bookings.locked.transfer_legs');
            }
            foreach ($legs as $leg) {
                $this->closing->assertOpen((string) $leg['booking_date']);
            }
            foreach ($legs as $leg) {
                if ((int) $leg['id'] === $bookingId && (int) $leg['version'] !== $expectedVersion) {
                    throw new VersionConflict($bookingId);
                }
            }
            foreach ($legs as $leg) {
                if ($leg['status'] !== $from) {
                    throw new BookingLocked($bookingId, 'bookings.locked.status.' . $leg['status']);
                }
            }

            $update = $this->pdo->prepare(
                'UPDATE bookings SET status = ?, version = version + 1, updated_at = now()
                  WHERE id = ? AND version = ? AND status = ?'
            );
            $log = new ChangeLog($this->pdo);
            foreach ($legs as $leg) {
                $update->execute([$to, (int) $leg['id'], (int) $leg['version'], $from]);
                if ($update->rowCount() !== 1) {
                    throw new VersionConflict($bookingId);
                }
                $log->record((int) $leg['id'], $userId, [['field' => 'status', 'from' => $from, 'to' => $to]], $reason);
            }
            return $this->legs((int) $booking['transfer_id']);
        });
    }
}
