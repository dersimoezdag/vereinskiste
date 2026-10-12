<?php
declare(strict_types=1);

namespace Vereinskiste\Ledger;

use LogicException;
use PDO;
use RuntimeException;

/**
 * "Abgeschlossen bis"-Datum (Tabelle `settings`, Schluessel `closed_until`; fehlt der Eintrag, ist
 * nichts abgeschlossen) und die Sperre fuer Buchungen im abgeschlossenen Zeitraum.
 *
 * Nebenlaeufigkeit: Alle Schreibwege einer Buchung rufen `assertOpen` innerhalb ihrer Transaktion auf.
 * Das nimmt eine transaktionsgebundene, geteilte Advisory-Sperre; `close`/`reopen` nehmen dieselbe
 * Sperre exklusiv. Eine Buchung, die die Pruefung bestanden hat, ist damit committed (oder zurueckgerollt),
 * bevor ein Abschluss wirksam wird, und eine Buchung, die danach beginnt, sieht den neuen Stand. Eine
 * Zeilensperre auf `settings` reicht dafuer nicht: vor dem ersten Abschluss gibt es keine Zeile.
 * Die Sperre wird in einem eigenen Statement genommen und der Wert danach gelesen; unter READ COMMITTED
 * sieht das Lesen so den Stand nach dem Warten.
 *
 * Das Rechtekonzept (nur Vorstand) setzt die Web-Schicht durch (`settings.manage`).
 */
final class ClosingService
{
    public const SETTING = 'closed_until';

    /** Schluessel der Advisory-Sperre (beliebige, feste Zahl; nur diese Klasse nutzt sie). */
    private const LOCK_KEY = 7340162;

    /** @param string|null $today heutiges Datum (YYYY-MM-DD); nur fuer Tests, sonst `date('Y-m-d')` */
    public function __construct(private PDO $pdo, private ?string $today = null)
    {
    }

    /** Abschlussdatum (YYYY-MM-DD) oder null, wenn nichts abgeschlossen ist. Ungesperrter, aktueller Lesezugriff. */
    public function closedUntil(): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([self::SETTING]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            return null;
        }
        if (!DateInput::valid($value)) {
            // Fail closed: ein unlesbarer Abschluss darf nie als "nichts abgeschlossen" gelten.
            throw new RuntimeException('Die gespeicherte Einstellung closed_until ist kein gueltiges Datum.');
        }
        return (string) $value;
    }

    /**
     * Wirft ClosedPeriod, wenn `$date` im abgeschlossenen Zeitraum liegt. Muss in der Transaktion der
     * Schreiboperation laufen, die das Datum betrifft (siehe Klassenkommentar). Die Sperre schliesst im
     * Zweifel (fail closed): ein ungueltiges Datum wird nie als "offen" gewertet.
     *
     * @throws ClosedPeriod
     * @throws LogicException ausserhalb einer Transaktion
     * @throws RuntimeException bei einem ungueltigen `$date` (aus der Datenbank gelesene Werte muessen gueltig
     *                          sein; der Aufrufer prueft Benutzereingaben vorher) oder gespeichertem Abschlussdatum
     */
    public function assertOpen(string $date): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Die Abschluss-Pruefung muss innerhalb einer Transaktion laufen.');
        }
        if (!DateInput::valid($date)) {
            throw new RuntimeException('Ungueltiges Buchungsdatum in der Abschluss-Pruefung: ' . $date);
        }
        $this->pdo->query('SELECT pg_advisory_xact_lock_shared(' . self::LOCK_KEY . ')');
        $closedUntil = $this->closedUntil();
        if ($closedUntil !== null && $date <= $closedUntil) {
            throw new ClosedPeriod($closedUntil);
        }
    }

    /**
     * Schliesst den Zeitraum bis einschliesslich `$date` ab. Nur vorwaerts, nicht nach heute.
     *
     * @throws ValidationError ['closed_until' => Textschluessel]
     */
    public function close(string $date, int $userId): void
    {
        if (!DateInput::valid($date)) {
            throw new ValidationError([self::SETTING => 'validation.date.invalid']);
        }
        if ($date > ($this->today ?? date('Y-m-d'))) {
            throw new ValidationError([self::SETTING => 'validation.closing.future']);
        }

        Transaction::run($this->pdo, function () use ($date, $userId): void {
            $this->lockExclusive();
            $from = $this->closedUntil();
            if ($from !== null && $date <= $from) {
                throw new ValidationError([self::SETTING => 'validation.closing.not_forward']);
            }
            $this->store($date);
            $this->audit('period_closed', $userId, ['from' => $from, 'to' => $date]);
        });
    }

    /**
     * Hebt den Abschluss teilweise (neues, frueheres Datum) oder ganz auf (`$date` leer). Pflichtgrund.
     *
     * @throws ValidationError ['closed_until' => ..., 'reason' => ...]
     */
    public function reopen(string $date, string $reason, int $userId): void
    {
        $date = trim($date);
        $errors = [];
        if ($date !== '' && !DateInput::valid($date)) {
            $errors[self::SETTING] = 'validation.date.invalid';
        }
        $reasonError = ChangeLog::reasonError($reason);
        if ($reasonError !== null) {
            $errors['reason'] = $reasonError;
        }
        if ($errors !== []) {
            throw new ValidationError($errors);
        }
        $reason = trim($reason);
        $to = $date === '' ? null : $date;

        Transaction::run($this->pdo, function () use ($to, $reason, $userId): void {
            $this->lockExclusive();
            $from = $this->closedUntil();
            if ($from === null) {
                throw new ValidationError([self::SETTING => 'validation.closing.not_closed']);
            }
            if ($to !== null && $to >= $from) {
                throw new ValidationError([self::SETTING => 'validation.closing.not_earlier']);
            }
            $this->store($to);
            $this->audit('period_reopened', $userId, ['from' => $from, 'to' => $to, 'reason' => $reason]);
        });
    }

    private function lockExclusive(): void
    {
        $this->pdo->query('SELECT pg_advisory_xact_lock(' . self::LOCK_KEY . ')');
    }

    /** `$date` null loescht die Einstellung (nichts abgeschlossen). */
    private function store(?string $date): void
    {
        if ($date === null) {
            $this->pdo->prepare('DELETE FROM settings WHERE key = ?')->execute([self::SETTING]);
            return;
        }
        $this->pdo->prepare(
            'INSERT INTO settings (key, value) VALUES (?, ?)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value'
        )->execute([self::SETTING, $date]);
    }

    /** @param array<string,mixed> $detail */
    private function audit(string $action, int $userId, array $detail): void
    {
        $this->pdo->prepare('INSERT INTO audit_log (user_id, action, detail) VALUES (?, ?, ?::jsonb)')
            ->execute([$userId, $action, json_encode($detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
    }
}
