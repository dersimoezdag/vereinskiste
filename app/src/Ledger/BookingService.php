<?php
declare(strict_types=1);

namespace Vereinskiste\Ledger;

use PDO;

/**
 * Anlegen und Aendern einzelner Buchungen. Jede Aenderung (Felder, Storno, Ruecknahme) erhoeht
 * die Version und schreibt in derselben Transaktion einen Verlaufseintrag mit Pflichtgrund.
 * Optimistisches Sperren: die Aenderung greift nur, wenn die Buchung noch die erwartete Version hat.
 * Umbuchungen (kind = transfer) werden nur ueber TransferService geaendert.
 */
final class BookingService
{
    public const DESCRIPTION_MAX = 500;
    public const COUNTERPARTY_MAX = 200;
    public const RECEIPT_NO_MAX = 100;

    /** Ueber `update` aenderbare Felder, in dieser Reihenfolge auch im Verlauf. */
    public const EDITABLE = [
        'booking_date', 'amount', 'direction', 'account_id', 'category_id', 'description', 'counterparty', 'receipt_no',
    ];

    private ClosingService $closing;

    public function __construct(private PDO $pdo)
    {
        $this->closing = new ClosingService($pdo);
    }

    /**
     * Legt eine regulaere, aktive Buchung (Version 1) an.
     *
     * @param array<string,mixed> $input account_id, booking_date, amount, direction, category_id?,
     *                                   description, counterparty?, receipt_no?
     * @return array<string,mixed> die angelegte Buchung (wie BookingRepository::find)
     * @throws ValidationError Feldname => Textschluessel
     * @throws ClosedPeriod wenn das Buchungsdatum im abgeschlossenen Zeitraum liegt
     */
    public function create(array $input, int $userId): array
    {
        [$values, $errors] = $this->validate($input, null);
        if ($errors !== []) {
            throw new ValidationError($errors);
        }
        return Transaction::run($this->pdo, function () use ($values, $userId): array {
            $this->closing->assertOpen($values['booking_date']);
            return $this->find($this->insert($values, 'regular', null, $userId));
        });
    }

    /**
     * Aendert Felder einer Buchung. Nur Schluessel aus EDITABLE zaehlen, fehlende bleiben unveraendert.
     * Ohne tatsaechliche Aenderung entsteht weder ein Verlaufseintrag noch eine neue Version.
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed> die Buchung nach der Aenderung
     * @throws ValidationError Feldfehler und/oder fehlender Grund (Schluessel "reason")
     * @throws VersionConflict wenn die Buchung inzwischen eine andere Version hat
     * @throws BookingNotFound
     * @throws BookingLocked bei Umbuchungen und stornierten Buchungen
     * @throws ClosedPeriod wenn altes oder neues Buchungsdatum im abgeschlossenen Zeitraum liegt
     */
    public function update(int $id, array $fields, string $reason, int $expectedVersion, int $userId): array
    {
        return Transaction::run($this->pdo, function () use ($id, $fields, $reason, $expectedVersion, $userId): array {
            $current = $this->lockForChange($id);
            $this->closing->assertOpen((string) $current['booking_date']);
            if ($current['status'] === 'cancelled') {
                throw new BookingLocked($id, 'bookings.locked.cancelled');
            }

            $merged = array_intersect_key($fields, array_flip(self::EDITABLE)) + self::asInput($current);
            [$values, $errors] = $this->validate($merged, $current);
            $this->closing->assertOpen($values['booking_date']);
            $reasonError = ChangeLog::reasonError($reason);
            if ($reasonError !== null) {
                $errors['reason'] = $reasonError;
            }
            if ($errors !== []) {
                throw new ValidationError($errors);
            }

            $changes = [];
            foreach (self::EDITABLE as $field) {
                if ($values[$field] !== $current[$field]) {
                    $changes[] = ['field' => $field, 'from' => $current[$field], 'to' => $values[$field]];
                }
            }
            if ($changes === []) {
                if ($current['version'] !== $expectedVersion) {
                    throw new VersionConflict($id);
                }
                return $current;
            }

            $stmt = $this->pdo->prepare(
                'UPDATE bookings
                    SET booking_date = :booking_date, amount = :amount, direction = :direction,
                        account_id = :account_id, category_id = :category_id, description = :description,
                        counterparty = :counterparty, receipt_no = :receipt_no,
                        version = version + 1, updated_at = now()
                  WHERE id = :id AND version = :expected'
            );
            $stmt->execute($values + ['id' => $id, 'expected' => $expectedVersion]);
            if ($stmt->rowCount() !== 1) {
                throw new VersionConflict($id);
            }
            (new ChangeLog($this->pdo))->record($id, $userId, $changes, $reason);
            return $this->find($id);
        });
    }

    /**
     * Storniert eine aktive Buchung (bleibt sichtbar, zaehlt nicht mehr in Salden).
     *
     * @return array<string,mixed>
     * @throws ValidationError|VersionConflict|BookingNotFound|BookingLocked|ClosedPeriod
     */
    public function cancel(int $id, string $reason, int $expectedVersion, int $userId): array
    {
        return $this->changeStatus($id, 'active', 'cancelled', $reason, $expectedVersion, $userId);
    }

    /**
     * Nimmt ein Storno zurueck.
     *
     * @return array<string,mixed>
     * @throws ValidationError|VersionConflict|BookingNotFound|BookingLocked|ClosedPeriod
     */
    public function restore(int $id, string $reason, int $expectedVersion, int $userId): array
    {
        return $this->changeStatus($id, 'cancelled', 'active', $reason, $expectedVersion, $userId);
    }

    /**
     * Legt eine sichtbare Korrekturbuchung an (kind = correction), optional mit Verweis auf die
     * Ursprungsbuchung. Der Grund steht im Anlage-Eintrag des Verlaufs und, falls keine Beschreibung
     * angegeben ist, auch als Beschreibung. Auch nach einem Abschluss moeglich, solange das Datum
     * nach dem Abschlussdatum liegt.
     *
     * @param array<string,mixed> $input wie bei create, description optional
     * @return array<string,mixed>
     * @throws ValidationError
     * @throws ClosedPeriod wenn das Datum der Korrektur im abgeschlossenen Zeitraum liegt
     */
    public function createCorrection(array $input, ?int $correctionOfId, string $reason, int $userId): array
    {
        $reasonError = ChangeLog::reasonError($reason);
        $description = $input['description'] ?? null;
        if ($reasonError === null && (!is_string($description) || TextInput::isBlank($description))) {
            $input['description'] = trim($reason);
        }

        [$values, $errors] = $this->validate($input, null);
        if ($reasonError !== null) {
            $errors['reason'] = $reasonError;
        }
        if ($correctionOfId !== null && (new BookingRepository($this->pdo))->find($correctionOfId) === null) {
            $errors['correction_of_id'] = 'validation.correction.original';
        }
        if ($errors !== []) {
            throw new ValidationError($errors);
        }

        return Transaction::run($this->pdo, function () use ($values, $correctionOfId, $reason, $userId): array {
            $this->closing->assertOpen($values['booking_date']);
            $id = $this->insert($values, 'correction', $correctionOfId, $userId);
            (new ChangeLog($this->pdo))->record(
                $id,
                $userId,
                [['field' => ChangeLog::CREATED, 'from' => null, 'to' => 'correction']],
                $reason
            );
            return $this->find($id);
        });
    }

    /** @return array<string,mixed> */
    private function changeStatus(int $id, string $from, string $to, string $reason, int $expectedVersion, int $userId): array
    {
        $reason = ChangeLog::normalizeReason($reason);
        return Transaction::run($this->pdo, function () use ($id, $from, $to, $reason, $expectedVersion, $userId): array {
            $current = $this->lockForChange($id);
            $this->closing->assertOpen((string) $current['booking_date']);
            if ($current['version'] !== $expectedVersion) {
                throw new VersionConflict($id);
            }
            if ($current['status'] !== $from) {
                throw new BookingLocked($id, 'bookings.locked.status.' . $current['status']);
            }
            $stmt = $this->pdo->prepare(
                'UPDATE bookings SET status = ?, version = version + 1, updated_at = now()
                  WHERE id = ? AND version = ? AND status = ?'
            );
            $stmt->execute([$to, $id, $expectedVersion, $from]);
            if ($stmt->rowCount() !== 1) {
                throw new VersionConflict($id);
            }
            (new ChangeLog($this->pdo))->record($id, $userId, [['field' => 'status', 'from' => $from, 'to' => $to]], $reason);
            return $this->find($id);
        });
    }

    /**
     * Sperrt die Zeile bis zum Ende der Transaktion und liefert den aktuellen Stand.
     *
     * @return array<string,mixed>
     */
    private function lockForChange(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT kind FROM bookings WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $kind = $stmt->fetchColumn();
        if ($kind === false) {
            throw new BookingNotFound($id);
        }
        if ($kind === 'transfer') {
            throw new BookingLocked($id, 'bookings.locked.transfer');
        }
        return $this->find($id);
    }

    /**
     * Prueft und normalisiert die Felder. Bei `$current` (Aenderung) muessen Konto und Kategorie nur
     * dann aktiv sein, wenn sie geaendert werden.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $current
     * @return array{0:array{booking_date:string,amount:?string,direction:string,account_id:?int,category_id:?int,
     *                       description:string,counterparty:?string,receipt_no:?string},1:array<string,string>}
     */
    private function validate(array $input, ?array $current): array
    {
        $errors = [];
        $str = static fn (string $key): string => is_string($input[$key] ?? null)
            ? trim($input[$key])
            : (is_int($input[$key] ?? null) ? (string) $input[$key] : '');
        $id = static fn (string $value): ?int => preg_match('/^[1-9]\d{0,17}\z/', $value) === 1 ? (int) $value : null;

        $accountId = $id($str('account_id'));
        if ($accountId === null || $accountId !== ($current['account_id'] ?? null)) {
            $account = $accountId === null ? null : (new AccountRepository($this->pdo))->find($accountId);
            if ($account === null || !$account['active']) {
                $errors['account_id'] = 'validation.booking.account';
            }
        }

        $categoryId = null;
        if ($str('category_id') !== '') {
            $categoryId = $id($str('category_id'));
            if ($categoryId === null || $categoryId !== ($current['category_id'] ?? null)) {
                $category = $categoryId === null ? null : (new CategoryRepository($this->pdo))->find($categoryId);
                if ($category === null || !$category['active']) {
                    $errors['category_id'] = 'validation.booking.category';
                }
            }
        }

        $date = $str('booking_date');
        if (!DateInput::valid($date)) {
            $errors['booking_date'] = 'validation.date.invalid';
        }

        $amount = null;
        try {
            $amount = Money::parse($str('amount'));
        } catch (InvalidAmount) {
            $errors['amount'] = 'validation.booking.amount';
        }

        $direction = $str('direction');
        if (!in_array($direction, BookingRepository::DIRECTIONS, true)) {
            $errors['direction'] = 'validation.booking.direction';
        }

        $description = $str('description');
        $error = self::textError(
            $description,
            self::DESCRIPTION_MAX,
            'validation.booking.description.too_long',
            'validation.booking.description.required'
        );
        if ($error !== null) {
            $errors['description'] = $error;
        }
        $counterparty = $str('counterparty');
        $error = self::textError($counterparty, self::COUNTERPARTY_MAX, 'validation.booking.counterparty.too_long');
        if ($error !== null) {
            $errors['counterparty'] = $error;
        }
        $receiptNo = $str('receipt_no');
        $error = self::textError($receiptNo, self::RECEIPT_NO_MAX, 'validation.booking.receipt_no.too_long');
        if ($error !== null) {
            $errors['receipt_no'] = $error;
        }

        return [[
            'booking_date' => $date,
            'amount' => $amount,
            'direction' => $direction,
            'account_id' => $accountId,
            'category_id' => $categoryId,
            'description' => $description,
            'counterparty' => $counterparty === '' ? null : $counterparty,
            'receipt_no' => $receiptNo === '' ? null : $receiptNo,
        ], $errors];
    }

    /**
     * Freitext: gueltiges UTF-8, bei Pflichtfeldern (`$requiredKey`) nicht leer im Sinne von
     * TextInput::isBlank (auch kein reiner Unicode-Leerraum), Laengengrenze.
     */
    public static function textError(string $value, int $max, string $tooLongKey, ?string $requiredKey = null): ?string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            return 'validation.text.encoding';
        }
        if ($requiredKey !== null && TextInput::isBlank($value)) {
            return $requiredKey;
        }
        return mb_strlen($value) > $max ? $tooLongKey : null;
    }

    /**
     * Aktueller Stand als Eingabe (fuer Felder, die eine Aenderung nicht mitschickt).
     *
     * @param array<string,mixed> $booking
     * @return array<string,string>
     */
    private static function asInput(array $booking): array
    {
        $input = [];
        foreach (self::EDITABLE as $field) {
            $input[$field] = (string) ($booking[$field] ?? '');
        }
        return $input;
    }

    /** @param array<string,mixed> $values validierte Werte aus validate() */
    private function insert(array $values, string $kind, ?int $correctionOfId, int $userId): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO bookings (account_id, booking_date, amount, direction, category_id, description,
                                   counterparty, receipt_no, kind, status, correction_of_id, version, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, 1, ?) RETURNING id"
        );
        $stmt->execute([
            $values['account_id'], $values['booking_date'], $values['amount'], $values['direction'],
            $values['category_id'], $values['description'], $values['counterparty'], $values['receipt_no'],
            $kind, $correctionOfId, $userId,
        ]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function find(int $id): array
    {
        $booking = (new BookingRepository($this->pdo))->find($id);
        if ($booking === null) {
            throw new BookingNotFound($id);
        }
        return $booking;
    }
}
