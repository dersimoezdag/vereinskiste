<?php
declare(strict_types=1);

namespace Vereinskiste\Club;

use DateTimeZone;
use PDO;
use Throwable;

/** Zeitzone des Vereins fuer Anzeige und Datumslogik (PHP und Datenbanksitzung). */
final class ClubTimezone
{
    public const DEFAULT = 'Europe/Berlin';

    public static function valid(string $zone): bool
    {
        return in_array($zone, DateTimeZone::listIdentifiers(), true);
    }

    /** Setzt die Vereinszeitzone; ohne lesbares Profil (Installation, ausstehende Migration) gilt der Standard. */
    public static function apply(PDO $pdo): string
    {
        $zone = self::DEFAULT;
        try {
            $value = $pdo->query("SELECT data->>'timezone' FROM club_profile WHERE id = 1")->fetchColumn();
            if (is_string($value) && self::valid($value)) {
                $zone = $value;
            }
            $pdo->prepare("SELECT set_config('TimeZone', ?, false)")->execute([$zone]);
        } catch (Throwable) {
            // Noch kein Schema: nur PHP umstellen, die Verbindung nicht weiter anfassen.
        }
        date_default_timezone_set($zone);
        return $zone;
    }
}
