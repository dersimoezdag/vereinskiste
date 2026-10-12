<?php
declare(strict_types=1);

namespace Vereinskiste\Ledger;

/** Darstellung kanonischer Betraege ("1234.50") fuer die Oberflaeche; rechnet nie mit float. */
final class MoneyFormat
{
    /** "-1234.5" => "-1.234,50 €" (Nicht-Betraege werden unveraendert zurueckgegeben). */
    public static function eur(string $amount): string
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $amount, $m) !== 1) {
            return $amount;
        }
        $int = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', ltrim($m[2], '0') ?: '0');
        $cents = str_pad($m[3] ?? '', 2, '0');
        $negative = $m[1] === '-' && trim($m[2] . $cents, '0') !== '';
        return ($negative ? '-' : '') . $int . ',' . $cents . "\u{00A0}€";
    }
}
