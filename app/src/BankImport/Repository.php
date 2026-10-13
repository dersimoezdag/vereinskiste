<?php
declare(strict_types=1);
namespace Vereinskiste\BankImport;

use PDO;

final class Repository
{
    public function __construct(private PDO $db) {}
    public function find(int $id, bool $lock = false): ?array
    {
        $stmt = $this->db->prepare('SELECT i.*,a.name AS account_name,a.iban,a.opening_date FROM bank_imports i
            JOIN accounts a ON a.id=i.account_id WHERE i.id=?' . ($lock ? ' FOR UPDATE OF i' : ''));
        $stmt->execute([$id]); $row = $stmt->fetch();
        if (!$row) { return null; }
        $row['settings'] = json_decode($row['settings'], true, 32, JSON_THROW_ON_ERROR);
        return $row;
    }
    public function list(int $page = 1): array
    {
        $stmt = $this->db->prepare('SELECT i.*,a.name AS account_name FROM bank_imports i JOIN accounts a ON a.id=i.account_id
            ORDER BY i.id DESC LIMIT 50 OFFSET ?');
        $stmt->execute([max(0, min(100000, $page - 1)) * 50]); return $stmt->fetchAll();
    }
    public function rows(int $id, int $page = 1): array
    {
        $stmt = $this->db->prepare('SELECT * FROM bank_import_rows WHERE import_id=? AND is_current ORDER BY row_number LIMIT 250 OFFSET ?');
        $stmt->execute([$id, max(0, min(40, $page - 1)) * 250]);
        $rows = $stmt->fetchAll();
        $account = (int) $this->find($id)['account_id'];
        foreach ($rows as &$row) {
            $row['proposal'] = json_decode($row['proposal'], true, 32, JSON_THROW_ON_ERROR);
            $row['issues'] = json_decode($row['issues'], true, 32, JSON_THROW_ON_ERROR);
            $row['candidates'] = $this->candidates($account, $row['proposal']);
            $same = $this->db->prepare("SELECT count(*) FROM bank_import_rows WHERE import_id=? AND is_current AND id<>?
                AND proposal->>'fingerprint'=? AND decision<>'excluded'");
            $same->execute([$id, $row['id'], $row['proposal']['fingerprint']]); $row['same_file'] = (int) $same->fetchColumn();
        }
        return $rows;
    }
    public function candidates(int $account, array $data): array
    {
        $stmt = $this->db->prepare("SELECT id,booking_date,amount,counterparty,purpose,bank_reference FROM bank_transactions
            WHERE account_id=? AND ((fingerprint=? AND (iban='' OR ?='' OR iban=?)) OR (bank_reference<>'' AND bank_reference=?))
            ORDER BY (bank_reference<>'' AND bank_reference=?) DESC,id LIMIT 20");
        $stmt->execute([$account, $data['fingerprint'], $data['iban'], $data['iban'], $data['bank_reference'], $data['bank_reference']]); return $stmt->fetchAll();
    }
    public function summary(int $id): array
    {
        $stmt = $this->db->prepare("SELECT count(*) AS parsed,
            count(*) FILTER (WHERE transaction_id IS NOT NULL) AS adopted,
            count(*) FILTER (WHERE decision='excluded') AS excluded,
            count(*) FILTER (WHERE decision='duplicate') AS duplicates,
            count(*) FILTER (WHERE issues<>'[]'::jsonb AND decision<>'excluded') AS errors,
            coalesce(sum((proposal->>'amount')::numeric) FILTER (WHERE issues='[]'::jsonb AND decision NOT IN ('excluded','duplicate')),0) AS amount
            FROM bank_import_rows WHERE import_id=? AND is_current");
        $stmt->execute([$id]); return $stmt->fetch();
    }
    public function profiles(): array { return $this->db->query('SELECT id,name FROM bank_csv_profiles ORDER BY name,id')->fetchAll(); }
}
