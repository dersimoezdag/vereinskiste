<?php
declare(strict_types=1);

namespace Vereinskiste\Ledger;

use RuntimeException;

/** Die Buchung wurde seit dem Laden (erwartete Version) von jemand anderem geaendert. */
final class VersionConflict extends RuntimeException
{
    public function __construct(public readonly int $bookingId)
    {
        parent::__construct("Buchung $bookingId wurde zwischenzeitlich geaendert.");
    }
}
