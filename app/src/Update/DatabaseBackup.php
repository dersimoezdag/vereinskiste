<?php
declare(strict_types=1);
namespace Vereinskiste\Update;

use PDO;

final class DatabaseBackup
{
    public const MAX_ROWS = 50000;
    public function __construct(private PDO $db, private string $directory) {}
    public function start(): array
    {
        Files::mkdir($this->directory);
        $schema = (new DatabaseSchema($this->db))->capture();
        Files::json($this->directory . '/schema.json', $schema);
        return ['table'=>0,'tables'=>count($schema['tables']),'last'=>null,'rows'=>0,'bytes'=>0,'total_bytes'=>0];
    }
    public function step(array $progress): array
    {
        $schema = Files::read($this->directory . '/schema.json');
        $deadline = microtime(true) + 3;
        for ($batch=0; $batch<4 && microtime(true)<$deadline; $batch++) {
            if (!isset($schema['tables'][$progress['table']])) {
                $hashes = ['schema.json'=>hash_file('sha256',$this->directory . '/schema.json')];
                foreach ($schema['tables'] as $i=>$unused) { $hashes[$i . '.jsonl'] = hash_file('sha256',$this->directory . '/' . $i . '.jsonl'); }
                Files::json($this->directory . '/complete.json', ['hashes'=>$hashes]); $progress['done']=true; return $progress;
            }
            $table = $schema['tables'][$progress['table']]; $keys = array_map([DatabaseSchema::class,'quote'], $table['keys']);
            $columns = implode(',',array_map([DatabaseSchema::class,'quote'],$table['columns']));
            $where = $progress['last'] === null ? '' : ' WHERE (' . implode(',',$keys) . ') > (' . implode(',',array_fill(0,count($keys),'?')) . ')';
            $stmt = $this->db->prepare('SELECT ' . $columns . ' FROM public.' . DatabaseSchema::quote($table['name']) . $where . ' ORDER BY ' . implode(',',$keys) . ' LIMIT 250');
            $stmt->execute($progress['last'] ?? []); $rows = $stmt->fetchAll();
            $path = $this->directory . '/' . $progress['table'] . '.jsonl';
            $stream = fopen($path,'c+b'); if (!$stream) { throw new Failure('backup'); }
            try {
                // Replay truncates an unfinished previous batch, then appends deterministically.
                ftruncate($stream,$progress['bytes']); fseek($stream,$progress['bytes']);
                foreach ($rows as $row) {
                    if (++$progress['rows'] > self::MAX_ROWS) { throw new Failure('backup_limit'); }
                    foreach ($row as &$value) { if (is_resource($value)) { $value='\\x'.bin2hex(stream_get_contents($value)); } }
                    unset($value);
                    $line = json_encode($row,JSON_THROW_ON_ERROR) . "\n";
                    $progress['total_bytes']+=strlen($line);
                    if ($progress['total_bytes']>536870912) { throw new Failure('backup_limit'); }
                    if (strlen($line)>1048576 || fwrite($stream,$line)!==strlen($line)) { throw new Failure('backup'); }
                    $progress['bytes']+=strlen($line);
                }
            } finally { fclose($stream); chmod($path,0600); }
            if ($rows === []) { $progress['table']++; $progress['last']=null; $progress['bytes']=0; }
            else { $last=end($rows); $progress['last']=array_map(static fn($key)=>$last[$key],$table['keys']); }
        }
        return $progress;
    }
}
