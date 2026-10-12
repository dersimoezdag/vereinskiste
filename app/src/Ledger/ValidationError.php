<?php
declare(strict_types=1);

namespace Vereinskiste\Ledger;

use RuntimeException;

/** Eingabe fachlich ungueltig; `errors` bildet Feldname auf einen Textschluessel ab. */
final class ValidationError extends RuntimeException
{
    /** @param array<string,string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Validierung fehlgeschlagen: ' . implode(', ', array_keys($errors)));
    }
}
