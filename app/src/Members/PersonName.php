<?php
declare(strict_types=1);

namespace Vereinskiste\Members;

use Vereinskiste\Foundation\Fields;
use Vereinskiste\Ledger\ValidationError;

/**
 * Namen von Personen getrennt: Anrede, Titel, Vorname, Nachname. Der Anzeigename (Titel Vorname Nachname)
 * wird daraus abgeleitet und fuer Suche, Listen und Verknuepfungen weiter als `members.name` gespeichert.
 */
final class PersonName
{
    /** Zulaessige Anreden (Schluessel; Beschriftung ueber `members.salutation.<schluessel>`). */
    public const SALUTATIONS = ['frau', 'herr', 'divers'];

    /** Feld => maximale Laenge */
    public const LIMITS = ['salutation' => 20, 'title' => 50, 'first_name' => 100, 'last_name' => 100];

    /**
     * Liest und prueft die vier Namensfelder. Kommt nur das fruehere Einzelfeld `name` (oder `applicant_name`)
     * vor, wird es am letzten Leerzeichen in Vor- und Nachname geteilt (Nachname genuegt dann).
     *
     * @return array{salutation:string,title:string,first_name:string,last_name:string}
     * @throws ValidationError
     */
    public static function read(array $input): array
    {
        $required = ['first_name', 'last_name'];
        if (!array_key_exists('first_name', $input) && !array_key_exists('last_name', $input)) {
            $legacy = $input['name'] ?? $input['applicant_name'] ?? '';
            if (is_string($legacy) && trim($legacy) !== '') {
                $input = array_replace($input, self::split($legacy));
                $required = ['last_name'];
            }
        }
        $values = Fields::read($input, self::LIMITS, $required);
        if ($values['salutation'] !== '' && !in_array($values['salutation'], self::SALUTATIONS, true)) {
            throw new ValidationError(['salutation' => 'foundation.invalid']);
        }
        return $values;
    }

    /** "Dr. Erika Muster" aus Titel, Vorname und Nachname (ohne Anrede), hoechstens 200 Zeichen. */
    public static function display(array $parts): string
    {
        $text = trim(implode(' ', array_filter(
            [(string) ($parts['title'] ?? ''), (string) ($parts['first_name'] ?? ''), (string) ($parts['last_name'] ?? '')],
            static fn (string $part): bool => $part !== ''
        )));
        return mb_substr($text, 0, 200);
    }

    /**
     * Teilt einen vollstaendigen Namen am letzten Leerzeichen: das letzte Wort ist der Nachname.
     *
     * @return array{first_name:string,last_name:string}
     */
    public static function split(string $name): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $position = mb_strrpos($name, ' ');
        if ($position === false) {
            return ['first_name' => '', 'last_name' => $name];
        }
        return ['first_name' => mb_substr($name, 0, $position), 'last_name' => mb_substr($name, $position + 1)];
    }
}
