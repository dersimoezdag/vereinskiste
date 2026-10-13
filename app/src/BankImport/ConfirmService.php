<?php
declare(strict_types=1);
namespace Vereinskiste\BankImport;

use PDO;
use Vereinskiste\Audit\AuditLog;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

/** One atomic batch, account lock and per-row identity; never writes bookings. */
final class ConfirmService
{
    public function __construct(private PDO $db) {}
    public function step(int $id, int $version, int $actor): void
    {
        Transaction::run($this->db, function () use ($id, $version, $actor): void {
            $repo = new Repository($this->db); $before = $repo->find($id);
            if (!$before) { throw new Conflict(); }
            // Same ordering in every confirmation: account, then import.
            $stmt = $this->db->prepare('SELECT active,type,iban,opening_date FROM accounts WHERE id=? FOR UPDATE');
            $stmt->execute([$before['account_id']]); $account = $stmt->fetch();
            $import = $repo->find($id, true);
            if ((int) $import['version'] !== $version) { throw new Conflict(); }
            if ($import['status'] === 'confirmed') { return; }
            if (!$account['active'] || $account['type'] !== 'bank' || !in_array($import['status'], ['review','confirming'], true)) { throw new Conflict(); }
            if ((int) $repo->summary($id)['errors'] > 0) { throw new ValidationError(['confirm' => 'bank.row.errors']); }
            $this->db->prepare('INSERT INTO bank_statements(import_id,account_id) VALUES (?,?) ON CONFLICT(import_id) DO NOTHING')
                ->execute([$id, $import['account_id']]);
            $stmt = $this->db->prepare('SELECT id FROM bank_statements WHERE import_id=?'); $stmt->execute([$id]); $statement = $stmt->fetchColumn();
            $stmt = $this->db->prepare("SELECT * FROM bank_import_rows WHERE import_id=? AND is_current AND transaction_id IS NULL
                AND decision NOT IN ('excluded','duplicate') ORDER BY row_number LIMIT 250");
            $stmt->execute([$id]); $blocked = false;
            foreach ($stmt->fetchAll() as $row) {
                $data = json_decode($row['proposal'], true, 32, JSON_THROW_ON_ERROR);
                if ($data['booking_date'] < $account['opening_date']
                    || ($data['account_iban'] !== '' && $data['account_iban'] !== $account['iban'])) {
                    throw new ValidationError(['confirm' => 'bank.row.account']);
                }
                $candidates = $repo->candidates((int) $import['account_id'], $data);
                $strongDuplicate = false;
                foreach ($candidates as $candidate) {
                    if ($data['bank_reference'] !== '' && $candidate['bank_reference'] === $data['bank_reference']) { $strongDuplicate = true; }
                }
                if ($row['decision'] === 'pending' || ($candidates !== [] && $row['decision'] === 'accept') || $strongDuplicate) {
                    $this->db->prepare("UPDATE bank_import_rows SET decision='pending' WHERE id=?")->execute([$row['id']]);
                    $blocked = true; break;
                }
                $insert = $this->db->prepare('INSERT INTO bank_transactions(account_id,statement_id,bank_reference,
                    booking_date,value_date,amount,currency,counterparty,iban,purpose,fingerprint)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?) RETURNING id');
                $insert->execute([$import['account_id'], $statement, $data['bank_reference'], $data['booking_date'], $data['value_date'],
                    $data['amount'], $data['currency'], $data['counterparty'], $data['iban'], $data['purpose'], $data['fingerprint']]);
                $this->db->prepare('UPDATE bank_import_rows SET transaction_id=? WHERE id=?')->execute([$insert->fetchColumn(), $row['id']]);
            }
            $stmt = $this->db->prepare("SELECT count(*) FROM bank_import_rows WHERE import_id=? AND is_current AND transaction_id IS NULL AND decision NOT IN ('excluded','duplicate')");
            $stmt->execute([$id]);
            $status = $blocked ? 'review' : ((int) $stmt->fetchColumn() === 0 ? 'confirmed' : 'confirming');
            $this->db->prepare('UPDATE bank_imports SET status=?,confirmed_by=?,version=version+1 WHERE id=?')->execute([$status, $actor, $id]);
            $this->db->prepare("UPDATE bank_statements SET date_from=s.minimum,date_to=s.maximum FROM
                (SELECT min((proposal->>'booking_date')::date) AS minimum,max((proposal->>'booking_date')::date) AS maximum
                 FROM bank_import_rows WHERE import_id=? AND is_current AND issues='[]'::jsonb AND decision<>'excluded') s WHERE import_id=?")
                ->execute([$id, $id]);
            (new AuditLog($this->db))->record('bank_import_confirmed', $actor, ['import_id' => $id, 'status' => $status]);
        });
    }
}
