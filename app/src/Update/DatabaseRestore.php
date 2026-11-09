<?php
declare(strict_types=1);
namespace Vereinskiste\Update;

use PDO;
use Vereinskiste\Ledger\Transaction;

/**
 * Restores a verified DatabaseBackup.
 *
 * run():  everything in one transaction; no partial restore becomes visible (manual restore, small databases).
 * step(): resumable in short requests for the auto-update rollback, which runs behind the maintenance freeze.
 *         Stages: reset (verify + recreate empty tables, atomic) -> data (per table, committed in blocks; the row
 *         count of the target table is the authoritative resume point) -> post (constraints, indexes, triggers,
 *         sequences, atomic). Every stage may be replayed after a crash without duplicating or losing rows.
 */
final class DatabaseRestore
{
    private const BLOCK = 500;
    public function __construct(private PDO $db, private string $directory, private int $timeout = 60) {}
    public function run(): void
    {
        $schema=$this->verified(); $deadline=microtime(true)+$this->timeout;
        Transaction::run($this->db,function() use($schema,$deadline): void {
            $this->limits();
            $this->reset($schema);
            foreach ($schema['tables'] as $i=>$table) {
                $stream=$this->open($i);
                try {
                    $batch=[];
                    while (($line=fgets($stream,1048578))!==false) {
                        if (microtime(true)>$deadline) { throw new Failure('restore_timeout'); }
                        $batch[]=json_decode($line,true,32,JSON_THROW_ON_ERROR);
                        if (count($batch)===100) { $this->insert($table,$batch); $batch=[]; }
                    }
                    if ($batch!==[]) { $this->insert($table,$batch); }
                } finally { fclose($stream); }
            }
            $this->post($schema);
        });
    }
    /** @param array|null $progress null starts a new restore; returns progress with 'done'=>true when finished. */
    public function step(?array $progress, float $budget = 3.0): array
    {
        $progress ??= ['stage'=>'reset','table'=>0,'rows'=>0,'offset'=>0];
        if ($progress['stage']==='reset') {
            $schema=$this->verified();
            Transaction::run($this->db,function() use($schema): void { $this->limits(); $this->reset($schema); });
            return ['stage'=>'data','table'=>0,'rows'=>0,'offset'=>0];
        }
        $schema=$this->schema();
        if ($progress['stage']==='data') {
            $deadline=microtime(true)+$budget;
            do {
                if (!isset($schema['tables'][$progress['table']])) { $progress['stage']='post'; break; }
                $progress=$this->block($schema['tables'][$progress['table']],$progress);
            } while (microtime(true)<$deadline);
            return $progress;
        }
        if ($progress['stage']==='post') {
            Transaction::run($this->db,function() use($schema): void { $this->limits(); $this->post($schema); });
            return ['stage'=>'done','done'=>true];
        }
        return $progress;
    }
    /** Inserts up to BLOCK rows of the current table in one transaction; advances to the next table at EOF. */
    private function block(array $table, array $progress): array
    {
        $i=$progress['table']; $quoted='public.' . DatabaseSchema::quote($table['name']);
        $present=(int)$this->db->query('SELECT count(*) FROM ' . $quoted)->fetchColumn();
        $stream=$this->open($i);
        try {
            if ($present===$progress['rows']) { fseek($stream,$progress['offset']); }
            else {
                // Progress was not saved after the last commit: the table content decides where to continue.
                for ($n=0; $n<$present; $n++) { if (fgets($stream,1048578)===false) { throw new Failure('backup'); } }
            }
            $rows=[];
            while (count($rows)<self::BLOCK && ($line=fgets($stream,1048578))!==false) { $rows[]=json_decode($line,true,32,JSON_THROW_ON_ERROR); }
            if ($rows===[]) { return ['stage'=>'data','table'=>$i+1,'rows'=>0,'offset'=>0]; }
            Transaction::run($this->db,function() use($table,$rows): void {
                $this->limits();
                foreach (array_chunk($rows,100) as $batch) { $this->insert($table,$batch); }
            });
            return ['stage'=>'data','table'=>$i,'rows'=>$present+count($rows),'offset'=>ftell($stream)];
        } finally { fclose($stream); }
    }
    private function verified(): array
    {
        $complete=Files::read($this->directory . '/complete.json');
        if ($complete===null) { throw new Failure('backup'); }
        foreach ($complete['hashes'] as $file=>$hash) {
            if (!preg_match('/^(schema\.json|\d+\.jsonl)\z/',$file)
                || !hash_equals($hash,hash_file('sha256',$this->directory . '/' . $file))) { throw new Failure('backup'); }
        }
        return $this->schema();
    }
    private function schema(): array
    {
        $schema=Files::read($this->directory . '/schema.json');
        if ($schema===null) { throw new Failure('backup'); }
        return $schema;
    }
    private function limits(): void
    {
        $this->db->exec("SET LOCAL lock_timeout='5000ms'; SET LOCAL statement_timeout='{$this->timeout}s'");
    }
    /** @return resource */
    private function open(int $i)
    {
        $stream=fopen($this->directory . '/' . $i . '.jsonl','rb'); if (!$stream) { throw new Failure('backup'); }
        return $stream;
    }
    private function reset(array $schema): void
    {
        foreach ((new DatabaseSchema($this->db))->tables() as $table) { $this->db->exec('DROP TABLE public.' . DatabaseSchema::quote($table) . ' CASCADE'); }
        foreach ($this->db->query("SELECT sequencename FROM pg_sequences WHERE schemaname='public'")->fetchAll(PDO::FETCH_COLUMN) as $sequence) {
            $this->db->exec('DROP SEQUENCE public.' . DatabaseSchema::quote($sequence) . ' CASCADE');
        }
        foreach ($schema['create'] as $sql) { $this->db->exec($sql); }
    }
    private function post(array $schema): void
    {
        foreach ($schema['post'] as $sql) { $this->db->exec($sql); }
        foreach ($schema['sequences'] as $sequence) {
            $this->db->prepare('SELECT setval(?::regclass,?::bigint,?::boolean)')->execute(['public.' . DatabaseSchema::quote($sequence['sequencename']),$sequence['last'],$sequence['called']?'true':'false']);
        }
    }
    private function insert(array $table,array $rows): void
    {
        $columns=$table['columns']; $values=[];
        foreach ($rows as $row) { foreach ($columns as $column) { $value=$row[$column]; $values[]=is_bool($value)?($value?'true':'false'):$value; } }
        $tuple='(' . implode(',',array_fill(0,count($columns),'?')) . ')';
        $sql='INSERT INTO public.' . DatabaseSchema::quote($table['name']) . '(' . implode(',',array_map([DatabaseSchema::class,'quote'],$columns))
            . ') OVERRIDING SYSTEM VALUE VALUES ' . implode(',',array_fill(0,count($rows),$tuple));
        $this->db->prepare($sql)->execute($values);
    }
}
