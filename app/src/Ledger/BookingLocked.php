<?php
declare(strict_types=1);

namespace Vereinskiste\Ledger;

use RuntimeException;

/**
 * Die Aenderung ist fuer diese Buchung fachlich nicht erlaubt (z. B. Umbuchung ueber die normale
 * Bearbeitung, stornierte Buchung bearbeiten); `textKey` erklaert dem Benutzer den Grund.
 */
final class BookingLocked extends RuntimeException
{
    public function __construct(public readonly int $bookingId, public readonly string $textKey)
    {
        parent::__construct("Buchung $bookingId: $textKey");
    }
}
