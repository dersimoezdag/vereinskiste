<?php
declare(strict_types=1);

namespace Vereinskiste\Ledger;

use DateTimeImmutable;

/**
 * Einzige Pruefung fuer Datumseingaben (YYYY-MM-DD). Das Jahr ist auf 1900..2999 begrenzt,
 * damit PostgreSQL nie mit einem unmoeglichen Datum (z. B. Jahr 0) konfrontiert wird.
 */
final class DateInput
{
    public const MIN_YEAR = 1900;
    public const MAX_YEAR = 2999;

    public static function valid(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^(\d{4})-\d{2}-\d{2}\z/', $value, $m) !== 1) {
            return false;
        }
        $year = (int) $m[1];
        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
