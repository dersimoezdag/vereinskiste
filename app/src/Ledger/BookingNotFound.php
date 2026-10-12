<?php
declare(strict_types=1);

namespace Vereinskiste\Ledger;

use RuntimeException;

/** Es gibt keine Buchung mit dieser id. */
final class BookingNotFound extends RuntimeException
{
    public function __construct(public readonly int $bookingId)
    {
        parent::__construct("Buchung $bookingId gibt es nicht.");
    }
}
