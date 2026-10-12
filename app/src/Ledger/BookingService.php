<?php
declare(strict_types=1);

namespace Vereinskiste\Ledger;

use DateTimeImmutable;
use PDO;

final class BookingService
{
    public const DESCRIPTION_MAX = 500;
    public const COUNTERPARTY_MAX = 200;
    public const RECEIPT_NO_MAX = 100;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Legt eine reguläre, aktive Buchung (Version 1) an.
     *
     * @param array<string,mixed> $input account_id, booking_date, amount, direction, category_id?,
     *                                   description, counterparty?, receipt_no?
     * @return array<string,mixed> die angelegte Buchung (wie BookingRepository::find)
     * @throws ValidationError Feldname => Textschluessel
     */
    public function create(array $input, int $userId): array
    {
        $errors = [];
        $str = static fn (string $key): string => is_string($input[$key] ?? null)
            ? trim($input[$key])
            : (is_int($input[$key] ?? null) ? (string) $input[$key] : '');

        $accounts = new AccountRepository($this->pdo);
        $account = preg_match('/^[1-9]\d{0,17}$/', $str('account_id')) === 1
            ? $accounts->find((int) $str('account_id'))
            : null;
        if ($account === null || !$account['active']) {
            $errors['account_id'] = 'validation.booking.account';
        }

        $categoryId = null;
        if ($str('category_id') !== '') {
            $category = preg_match('/^[1-9]\d{0,17}$/', $str('category_id')) === 1
                ? (new CategoryRepository($this->pdo))->find((int) $str('category_id'))
                : null;
            if ($category === null || !$category['active']) {
                $errors['category_id'] = 'validation.booking.category';
            } else {
                $categoryId = $category['id'];
            }
        }

        $date = $str('booking_date');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
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
        if ($description === '') {
            $errors['description'] = 'validation.booking.description.required';
        } elseif (mb_strlen($description) > self::DESCRIPTION_MAX) {
            $errors['description'] = 'validation.booking.description.too_long';
        }
        $counterparty = $str('counterparty');
        if (mb_strlen($counterparty) > self::COUNTERPARTY_MAX) {
            $errors['counterparty'] = 'validation.booking.counterparty.too_long';
        }
        $receiptNo = $str('receipt_no');
        if (mb_strlen($receiptNo) > self::RECEIPT_NO_MAX) {
            $errors['receipt_no'] = 'validation.booking.receipt_no.too_long';
        }

        if ($errors !== []) {
            throw new ValidationError($errors);
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO bookings (account_id, booking_date, amount, direction, category_id, description,
                                   counterparty, receipt_no, kind, status, version, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'regular', 'active', 1, ?) RETURNING id"
        );
        $stmt->execute([
            $account['id'], $date, $amount, $direction, $categoryId, $description,
            $counterparty === '' ? null : $counterparty, $receiptNo === '' ? null : $receiptNo, $userId,
        ]);

        /** @var array<string,mixed> */
        return (new BookingRepository($this->pdo))->find((int) $stmt->fetchColumn());
    }
}
