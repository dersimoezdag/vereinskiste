<?php
declare(strict_types=1);

namespace Vereinskiste\Audit;

use PDO;

/**
 * Zugriff auf das Protokoll (Tabelle `audit_log`, per Trigger unveraenderlich): Lesen fuer die
 * Protokollseite, `record` fuer die Stammdaten (Konten, Kategorien). Aeltere Schreibstellen
 * (Benutzerverwaltung, Abschluss) schreiben noch selbst in die Tabelle.
 */
final class AuditLog
{
    /** Bekannte Aktionen (Filter-Whitelist); Beschriftung ueber `audit.action.<aktion>`. */
    public const ACTIONS = [
        'login', 'login_failed', 'period_closed', 'period_reopened',
        'user_created', 'user_updated', 'user_deactivated', 'user_activated',
        'user_password_reset', 'password_changed', 'password_reset_completed',
        'account_created', 'account_renamed', 'account_activated', 'account_deactivated',
        'category_created', 'category_renamed', 'category_activated', 'category_deactivated',
        'foundation_updated', 'privacy_settings_updated', 'member_anonymized', 'application_anonymized', 'application_mail_retried', 'invoice_updated',
        'bank_import_uploaded', 'bank_import_reviewed', 'bank_import_confirmed', 'bank_import_cancelled', 'bank_import_reset',
        'auto_update_started', 'auto_update_completed', 'auto_update_rolled_back',
        'member_user_linked', 'member_user_unlinked',
        'membership_application_approved', 'membership_application_rejected',
    ];

    /** Aktionen, deren Details nie angezeigt werden (keine Personendaten im Protokoll-Bildschirm). */
    private const NO_DETAIL = ['login_failed'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Schreibt einen Eintrag. Der Aufrufer oeffnet vorher die Transaktion der protokollierten
     * Aenderung, damit beides gemeinsam bestehen oder scheitern.
     *
     * @param array<string,mixed> $detail keine Personendaten
     */
    public function record(string $action, ?int $userId, array $detail = []): void
    {
        $this->pdo
            ->prepare('INSERT INTO audit_log (user_id, action, detail) VALUES (?, ?, ?::jsonb)')
            ->execute([
                $userId, $action,
                json_encode((object) $detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
    }

    /** Unbekannte Filterwerte werden zu null (kein Filter). */
    public static function normalizeAction(mixed $action): ?string
    {
        return is_string($action) && in_array($action, self::ACTIONS, true) ? $action : null;
    }

    public function count(?string $action): int
    {
        $stmt = $this->pdo->prepare('SELECT count(*) FROM audit_log WHERE (?::text IS NULL OR action = ?)');
        $stmt->execute([$action, $action]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Neueste zuerst.
     *
     * @return list<array{id:int,at:string,action:string,user_name:?string,
     *                    detail:list<array{key:string,value:?string,is_date:bool}>}>
     */
    public function page(?string $action, int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.at::text AS at, a.action, a.detail::text AS detail, u.name AS user_name
               FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
              WHERE (?::text IS NULL OR a.action = ?)
              ORDER BY a.at DESC, a.id DESC
              LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $action);
        $stmt->bindValue(2, $action);
        $stmt->bindValue(3, max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(4, max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'at' => (string) $row['at'],
                'action' => (string) $row['action'],
                'user_name' => $row['user_name'] === null ? null : (string) $row['user_name'],
                'detail' => in_array($row['action'], self::NO_DETAIL, true) ? [] : self::readable((string) $row['detail']),
            ];
        }
        return $rows;
    }

    /**
     * Detail-JSON als lesbare Schluessel/Wert-Paare (Skalare als Text, null als null, Verschachteltes als JSON).
     *
     * @return list<array{key:string,value:?string,is_date:bool}>
     */
    private static function readable(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                $text = null;
            } elseif (is_bool($value)) {
                $text = $value ? 'ja' : 'nein';
            } elseif (is_scalar($value)) {
                $text = (string) $value;
            } elseif (is_array($value) && array_is_list($value) && array_filter($value, 'is_scalar') === $value) {
                // Liste einfacher Werte (z. B. Rollen): kommagetrennt statt als JSON
                $text = implode(', ', array_map('strval', $value));
            } else {
                $text = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            }
            $out[] = [
                'key' => (string) $key,
                'value' => $text,
                'is_date' => $text !== null && preg_match('/^\d{4}-\d{2}-\d{2}\z/', $text) === 1,
            ];
        }
        return $out;
    }
}
