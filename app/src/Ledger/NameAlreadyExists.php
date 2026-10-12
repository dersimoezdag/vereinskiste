<?php
declare(strict_types=1);

namespace Vereinskiste\Ledger;

use PDOException;
use RuntimeException;

/**
 * Konto- oder Kategoriename existiert bereits (ohne Beachtung der Gross-/Kleinschreibung).
 * Wird aus der Datenbank-Eindeutigkeit (SQLSTATE 23505) abgeleitet und deckt damit auch
 * Wettlaeufe ab, die die vorherige Anwendungspruefung (`nameExists`) bestehen.
 */
final class NameAlreadyExists extends RuntimeException
{
    private const UNIQUE_VIOLATION = '23505';

    /**
     * Wandelt eine Eindeutigkeitsverletzung um.
     *
     * @throws PDOException (die urspruengliche), wenn es keine Eindeutigkeitsverletzung ist
     */
    public static function from(PDOException $e): self
    {
        if ($e->getCode() !== self::UNIQUE_VIOLATION) {
            throw $e;
        }
        return new self('Name existiert bereits.', 0, $e);
    }
}
