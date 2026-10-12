<?php
declare(strict_types=1);

namespace Vereinskiste\Ledger;

use InvalidArgumentException;

/** Eingabe ist kein gueltiger Betrag (Format, Wertebereich). */
final class InvalidAmount extends InvalidArgumentException
{
}
