<?php
declare(strict_types=1);

namespace Vereinskiste\Receipts;

use RuntimeException;

/** Es gibt keinen Beleg mit dieser id (oder seine Datei fehlt im Speicher). */
final class ReceiptNotFound extends RuntimeException
{
    public function __construct(public readonly int $receiptId)
    {
        parent::__construct("Beleg $receiptId gibt es nicht.");
    }
}
