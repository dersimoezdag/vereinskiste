<?php
declare(strict_types=1);
namespace Vereinskiste\BankImport;

use PDO;
use Vereinskiste\Audit\AuditLog;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

final class ReviewService
{
    public function __construct(private PDO $db) {}
    public function decide(int $id, int $version, int $number, array $input, int $actor): void
    {
        Transaction::run($this->db, function () use ($id, $version, $number, $input, $actor): void {
            $repo = new Repository($this->db); $import = $repo->find($id, true);
            if (!$import || (int) $import['version'] !== $version || $import['status'] !== 'review') { throw new Conflict(); }
            $stmt = $this->db->prepare('SELECT * FROM bank_import_rows WHERE import_id=? AND row_number=? AND is_current'); $stmt->execute([$id, $number]);
            $row = $stmt->fetch();
            if (!$row || $row['transaction_id'] !== null) { throw new Conflict(); }
            $decision = $input['decision'] ?? '';
            if (!in_array($decision, ['new','duplicate','excluded'], true)) { throw new ValidationError(['decision' => 'bank.decision.invalid']); }
            $reason = Fields::read($input, ['reason' => 500], ['reason'])['reason'];
            $data = json_decode($row['proposal'], true, 32, JSON_THROW_ON_ERROR); $duplicate = null;
            if ($decision === 'duplicate') {
                foreach ($repo->candidates((int) $import['account_id'], $data) as $candidate) {
                    if ((string) $candidate['id'] === ($input['duplicate_id'] ?? '')) { $duplicate = (int) $candidate['id']; }
                }
                if ($duplicate === null) { throw new ValidationError(['decision' => 'bank.decision.invalid']); }
            }
            if ($decision !== 'excluded' && json_decode($row['issues'], true) !== []) { throw new ValidationError(['decision' => 'bank.row.errors']); }
            $this->db->prepare('UPDATE bank_import_rows SET decision=?,reason=?,duplicate_id=? WHERE id=?')->execute([$decision, $reason, $duplicate, $row['id']]);
            $this->db->prepare('UPDATE bank_imports SET version=version+1 WHERE id=?')->execute([$id]);
            (new AuditLog($this->db))->record('bank_import_reviewed', $actor, ['import_id' => $id, 'row' => $number, 'decision' => $decision]);
        });
    }
    public function cancel(int $id, int $version, int $actor): void
    {
        Transaction::run($this->db, function () use ($id, $version, $actor): void {
            $repo = new Repository($this->db); $import = $repo->find($id, true);
            if (!$import || (int) $import['version'] !== $version || !in_array($import['status'], ['uploaded','parsing','review','failed'], true)
                || (int) $repo->summary($id)['adopted'] > 0) { throw new Conflict(); }
            $this->db->prepare("UPDATE bank_imports SET status='cancelled',version=version+1 WHERE id=?")->execute([$id]);
            (new AuditLog($this->db))->record('bank_import_cancelled', $actor, ['import_id' => $id]);
        });
    }
    public function reset(int $id, int $version, string $reason, int $actor): void
    {
        $reason = Fields::read(['reason' => $reason], ['reason' => 500], ['reason'])['reason'];
        Transaction::run($this->db, function () use ($id, $version, $reason, $actor): void {
            $repo = new Repository($this->db); $import = $repo->find($id, true);
            if (!$import || (int) $import['version'] !== $version || !in_array($import['status'], ['review','failed','cancelled'], true)
                || (int) $repo->summary($id)['adopted'] > 0) { throw new Conflict(); }
            $this->db->prepare('INSERT INTO bank_import_attempts(import_id,revision,settings,reason,actor) VALUES (?,?,?::jsonb,?,?)')
                ->execute([$id, $import['parse_revision'], json_encode($import['settings'], JSON_THROW_ON_ERROR), $reason, $actor]);
            $this->db->prepare('UPDATE bank_import_rows SET is_current=false WHERE import_id=? AND is_current')->execute([$id]);
            $settings = array_diff_key($import['settings'], array_flip(['mapping','date_format','decimal_format','reference_unique']));
            $this->db->prepare("UPDATE bank_imports SET settings=?::jsonb,status='uploaded',parsed_rows=0,error_code=NULL,
                parse_revision=parse_revision+1,version=version+1 WHERE id=?")->execute([json_encode($settings, JSON_THROW_ON_ERROR), $id]);
            (new AuditLog($this->db))->record('bank_import_reset', $actor, ['import_id' => $id]);
        });
    }
}
