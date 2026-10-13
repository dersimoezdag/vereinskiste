<?php
declare(strict_types=1);
namespace Vereinskiste\Update;

use PDO;

/** PostgreSQL schema subset used by Vereinskiste; unknown objects fail closed. */
final class DatabaseSchema
{
    public function __construct(private PDO $db) {}
    public static function quote(string $name): string { return '"' . str_replace('"', '""', $name) . '"'; }
    public function tables(): array
    {
        return $this->db->query("SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname='public' AND c.relkind='r' ORDER BY c.relname")->fetchAll(PDO::FETCH_COLUMN);
    }
    public function capture(): array
    {
        $unsupported = $this->db->query("SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname='public' AND (c.relkind NOT IN ('r','S','i') OR c.relowner<>current_user::regrole) LIMIT 1")->fetchColumn();
        $types = $this->db->query("SELECT 1 FROM pg_type t JOIN pg_namespace n ON n.oid=t.typnamespace
            WHERE n.nspname='public' AND t.typtype IN ('e','d') LIMIT 1")->fetchColumn();
        $foreign = $this->db->query("SELECT 1 FROM pg_constraint c JOIN pg_class a ON a.oid=c.conrelid JOIN pg_class b ON b.oid=c.confrelid
            JOIN pg_namespace x ON x.oid=a.relnamespace JOIN pg_namespace y ON y.oid=b.relnamespace
            WHERE c.contype='f' AND (x.nspname='public')<>(y.nspname='public') LIMIT 1")->fetchColumn();
        if ($unsupported !== false || $types !== false || $foreign !== false) { throw new Failure('schema'); }
        $create = []; $post = []; $tables = [];
        $sequences = $this->db->query("SELECT s.*,EXISTS(SELECT 1 FROM pg_depend d JOIN pg_class c ON c.oid=d.objid
            JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=s.schemaname AND c.relname=s.sequencename AND d.deptype='i') AS identity
            FROM pg_sequences s WHERE schemaname='public' ORDER BY sequencename")->fetchAll();
        foreach ($sequences as &$sequence) {
            $name = self::quote($sequence['sequencename']);
            $values = $this->db->query('SELECT last_value,is_called FROM public.' . $name)->fetch();
            $sequence['last'] = (string) $values['last_value']; $sequence['called'] = $values['is_called'];
            if (!$sequence['identity']) {
                $create[] = 'CREATE SEQUENCE public.' . $name . ' AS ' . $sequence['data_type'] . ' INCREMENT ' . $sequence['increment_by']
                    . ' MINVALUE ' . $sequence['min_value'] . ' MAXVALUE ' . $sequence['max_value'] . ' START ' . $sequence['start_value']
                    . ' CACHE ' . $sequence['cache_size'] . ($sequence['cycle'] ? ' CYCLE' : ' NO CYCLE');
            }
        }
        unset($sequence);
        foreach ($this->tables() as $table) {
            $stmt = $this->db->prepare("SELECT a.attname,format_type(a.atttypid,a.atttypmod) AS type,a.attnotnull,a.attidentity,a.attgenerated,
                pg_get_expr(d.adbin,d.adrelid) AS expression FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid
                JOIN pg_namespace n ON n.oid=c.relnamespace LEFT JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum
                WHERE n.nspname='public' AND c.relname=? AND a.attnum>0 AND NOT a.attisdropped ORDER BY a.attnum");
            $stmt->execute([$table]); $columns = $stmt->fetchAll(); $definitions = []; $insertColumns = [];
            foreach ($columns as $column) {
                $definition = self::quote($column['attname']) . ' ' . $column['type'];
                if ($column['attidentity'] !== '') { $definition .= ' GENERATED ' . ($column['attidentity']==='a' ? 'ALWAYS' : 'BY DEFAULT') . ' AS IDENTITY'; }
                elseif ($column['attgenerated'] !== '') { $definition .= ' GENERATED ALWAYS AS (' . $column['expression'] . ') STORED'; }
                elseif ($column['expression'] !== null) { $definition .= ' DEFAULT ' . $column['expression']; }
                if ($column['attnotnull']) { $definition .= ' NOT NULL'; }
                $definitions[] = $definition;
                if ($column['attgenerated'] === '') { $insertColumns[] = $column['attname']; }
            }
            $create[] = 'CREATE TABLE public.' . self::quote($table) . ' (' . implode(',', $definitions) . ')';
            $pk = $this->db->prepare("SELECT a.attname FROM pg_index i JOIN pg_class c ON c.oid=i.indrelid JOIN pg_namespace n ON n.oid=c.relnamespace
                CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY k(num,position) JOIN pg_attribute a ON a.attrelid=c.oid AND a.attnum=k.num
                WHERE i.indisprimary AND n.nspname='public' AND c.relname=? ORDER BY k.position");
            $pk->execute([$table]); $keys = $pk->fetchAll(PDO::FETCH_COLUMN);
            if ($keys === []) { throw new Failure('schema'); }
            $tables[] = ['name'=>$table,'columns'=>$insertColumns,'keys'=>$keys];
        }
        foreach ($this->db->query("SELECT pg_get_functiondef(p.oid) AS definition,t.typname FROM pg_proc p
            JOIN pg_namespace n ON n.oid=p.pronamespace JOIN pg_type t ON t.oid=p.prorettype WHERE n.nspname='public'")->fetchAll() as $function) {
            if ($function['typname'] !== 'trigger') { throw new Failure('schema'); }
            $post[] = $function['definition'];
        }
        foreach ($this->db->query("SELECT c.relname,k.conname,k.contype,pg_get_constraintdef(k.oid,true) AS definition FROM pg_constraint k
            JOIN pg_class c ON c.oid=k.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public'
            ORDER BY (k.contype='f'),c.relname,k.conname")->fetchAll() as $constraint) {
            $post[] = 'ALTER TABLE public.' . self::quote($constraint['relname']) . ' ADD CONSTRAINT ' . self::quote($constraint['conname']) . ' ' . $constraint['definition'];
        }
        foreach ($this->db->query("SELECT pg_get_indexdef(i.indexrelid) FROM pg_index i JOIN pg_class c ON c.oid=i.indrelid
            JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND NOT EXISTS(SELECT 1 FROM pg_constraint k WHERE k.conindid=i.indexrelid)")->fetchAll(PDO::FETCH_COLUMN) as $index) { $post[] = $index; }
        foreach ($this->db->query("SELECT pg_get_triggerdef(t.oid) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid
            JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND NOT t.tgisinternal")->fetchAll(PDO::FETCH_COLUMN) as $trigger) { $post[] = $trigger; }
        return ['tables'=>$tables,'create'=>$create,'post'=>$post,'sequences'=>$sequences];
    }
}
