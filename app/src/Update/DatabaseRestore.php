<?php
declare(strict_types=1);
namespace Vereinskiste\Update;

use PDO;
use Vereinskiste\Ledger\Transaction;

/** All schema/data restoration is one transaction; no partial restore becomes visible. */
final class DatabaseRestore
{
    public function __construct(private PDO $db, private string $directory) {}
    public function run(): void
    {
        $complete=Files::read($this->directory . '/complete.json');
        if ($complete===null) { throw new Failure('backup'); }
        foreach ($complete['hashes'] as $file=>$hash) {
            if (!preg_match('/^(schema\.json|\d+\.jsonl)\z/',$file)
                || !hash_equals($hash,hash_file('sha256',$this->directory . '/' . $file))) { throw new Failure('backup'); }
        }
        $schema=Files::read($this->directory . '/schema.json'); $deadline=microtime(true)+14;
        Transaction::run($this->db,function() use($schema,$deadline): void {
            $this->db->exec("SET LOCAL lock_timeout='1000ms'; SET LOCAL statement_timeout='5000ms'");
            foreach ((new DatabaseSchema($this->db))->tables() as $table) { $this->db->exec('DROP TABLE public.' . DatabaseSchema::quote($table) . ' CASCADE'); }
            foreach ($this->db->query("SELECT sequencename FROM pg_sequences WHERE schemaname='public'")->fetchAll(PDO::FETCH_COLUMN) as $sequence) {
                $this->db->exec('DROP SEQUENCE public.' . DatabaseSchema::quote($sequence) . ' CASCADE');
            }
            foreach ($schema['create'] as $sql) { $this->db->exec($sql); }
            foreach ($schema['tables'] as $i=>$table) {
                $stream=fopen($this->directory . '/' . $i . '.jsonl','rb'); if (!$stream) { throw new Failure('backup'); }
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
            foreach ($schema['post'] as $sql) { $this->db->exec($sql); }
            foreach ($schema['sequences'] as $sequence) {
                $this->db->prepare('SELECT setval(?::regclass,?::bigint,?::boolean)')->execute(['public.' . DatabaseSchema::quote($sequence['sequencename']),$sequence['last'],$sequence['called']?'true':'false']);
            }
        });
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
