<?php
declare(strict_types=1);

namespace Vereinskiste\Ledger;

/**
 * Betraege exakt: intern ganzzahlige Cent, extern kanonische Strings ("12.50", "-3.00").
 * Nie float, kein bcmath noetig.
 *
 * Akzeptierte Eingaben (Leerzeichen werden ignoriert):
 *  - "12", "12.5", "12.50"          Punkt als Dezimaltrennzeichen, ohne Tausendertrennung
 *  - "12,5", "12,50"                Komma als Dezimaltrennzeichen
 *  - "1.234,56", "1.234.567,89"     Punkt als Tausendertrennzeichen, nur zusammen mit Komma
 *    (Gruppen zu je genau 3 Ziffern; "1.234" allein waere 3 Nachkommastellen und wird abgelehnt)
 * Hoechstens 2 Nachkommastellen, hoechstens 7 Vorkommastellen (max. 9.999.999,99).
 * Abgelehnt: leer, Buchstaben, Tausenderkomma ("1,234.56"), mehr als 2 Nachkommastellen.
 * `parse` verlangt einen Betrag > 0 (kein Vorzeichen); `parseSigned` erlaubt ein fuehrendes "-" und 0.
 */
final class Money
{
    public const MAX_CENTS = 999999999;

    /** @throws InvalidAmount */
    public static function parse(string $input): string
    {
        $cents = self::toCents($input);
        if ($cents <= 0) {
            throw new InvalidAmount('Betrag muss groesser als 0 sein.');
        }
        return self::fromCents($cents);
    }

    /** @throws InvalidAmount */
    public static function parseSigned(string $input): string
    {
        return self::fromCents(self::toCents($input, true));
    }

    /** Kanonischer String ("12.50", "-0.05") => Cent. @throws InvalidAmount */
    public static function toCents(string $input, bool $allowNegative = false): int
    {
        $s = str_replace([' ', "\u{00A0}"], '', $input);
        $negative = false;
        if ($allowNegative && str_starts_with($s, '-')) {
            $negative = true;
            $s = substr($s, 1);
        }
        if (str_contains($s, ',')) {
            if (preg_match('/^(\d{1,3}(?:\.\d{3})*|\d+),(\d{1,2})\z/', $s, $m) !== 1) {
                throw new InvalidAmount('Ungueltiger Betrag.');
            }
            $int = str_replace('.', '', $m[1]);
            $frac = $m[2];
        } else {
            if (preg_match('/^(\d+)(?:\.(\d{1,2}))?\z/', $s, $m) !== 1) {
                throw new InvalidAmount('Ungueltiger Betrag.');
            }
            $int = $m[1];
            $frac = $m[2] ?? '';
        }
        $int = ltrim($int, '0');
        if (strlen($int) > 7) {
            throw new InvalidAmount('Betrag zu gross.');
        }
        $cents = ((int) ($int === '' ? '0' : $int)) * 100 + (int) str_pad($frac, 2, '0');
        if ($cents > self::MAX_CENTS) {
            throw new InvalidAmount('Betrag zu gross.');
        }
        return $negative ? -$cents : $cents;
    }

    public static function fromCents(int $cents): string
    {
        $abs = abs($cents);
        return ($cents < 0 ? '-' : '') . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
