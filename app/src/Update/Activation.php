<?php
declare(strict_types=1);
namespace Vereinskiste\Update;

use PDO;
use Vereinskiste\Audit\AuditLog;
use Vereinskiste\Install\Updater;
use Vereinskiste\Ledger\Transaction;

final class Activation
{
    public function __construct(private PDO $db,private Runtime $runtime,private State $state) {}
    public function committed(array $data): bool
    {
        $stmt=$this->db->prepare("SELECT value FROM settings WHERE key='auto_update_committed'"); $stmt->execute();
        return $stmt->fetchColumn()===$data['id'];
    }
    public function apply(array $data): array
    {
        $code=$this->runtime->release($data['id']) . '/app';
        if (!$this->committed($data)) {
            Transaction::run($this->db,function() use($data,$code): void {
                $this->db->exec("SET LOCAL lock_timeout='1000ms'; SET LOCAL statement_timeout='10000ms'");
                (new Updater($this->db,$code . '/migrations'))->run(true);
                // Keep protocol-1 rollback/export support after the migration.
                (new DatabaseSchema($this->db))->capture();
                $this->db->prepare("INSERT INTO settings(key,value) VALUES ('auto_update_committed',?) ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value")
                    ->execute([$data['id']]);
                $this->runtime->pointer(['id'=>$data['id'],'version'=>$data['version']]);
            });
        } else { $this->runtime->pointer(['id'=>$data['id'],'version'=>$data['version']]); }
        $data['phase']='verify'; $data['verify_until']=time()+90; return $data;
    }
    public function verify(array $data): array
    {
        $probe=Files::read($this->runtime->directory() . '/probe.json');
        if ($probe!==null && ($probe['id']??null)===$data['id'] && ($probe['version']??null)===$data['version']) {
            (new AuditLog($this->db))->record('auto_update_completed',$data['actor'],['operation'=>$data['id'],'version'=>$data['version']]);
            $data['phase']='done'; $this->state->save($data); $this->unfreeze(); return $data;
        }
        if (time()>$data['verify_until']) { $data['phase']='rollback'; $data['error']='health'; }
        return $data;
    }
    public function rollback(array $data): array
    {
        if ($this->committed($data)) { (new DatabaseRestore($this->db,$this->state->path($data) . '/backup'))->run(); }
        $this->runtime->pointer($data['previous']);
        (new AuditLog($this->db))->record('auto_update_rolled_back',$data['actor'],['operation'=>$data['id'],'version'=>$data['version']]);
        $data['phase']='rolled_back'; $this->state->save($data); $this->unfreeze(); return $data;
    }
    private function unfreeze(): void
    {
        foreach (['maintenance.json','probe.json'] as $name) {
            $path=$this->runtime->directory() . '/' . $name; if (is_file($path)) { unlink($path); }
        }
    }
}
