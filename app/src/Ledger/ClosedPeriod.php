<?php
declare(strict_types=1);

namespace Vereinskiste\Ledger;

use RuntimeException;

/**
 * Die Buchung liegt (alt oder neu) im abgeschlossenen Zeitraum (`booking_date <= closedUntil`).
 * Korrekturen nach dem Abschlussdatum sind weiterhin moeglich.
 */
final class ClosedPeriod extends RuntimeException
{
    /** @param string $closedUntil Abschlussdatum (YYYY-MM-DD), einschliesslich */
    public function __construct(public readonly string $closedUntil)
    {
        parent::__construct("Der Zeitraum bis $closedUntil ist abgeschlossen.");
    }
}
