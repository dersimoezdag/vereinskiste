<?php
declare(strict_types=1);
namespace Vereinskiste\Update;

final class State
{
    public function __construct(private Runtime $runtime) {}
    public function load(): ?array { return Files::read($this->runtime->directory() . '/operation.json'); }
    public function save(array $data): void
    {
        Files::json($this->runtime->directory() . '/operation.json',$data);
        Files::json($this->path($data) . '/state.json',$data);
    }
    public function path(array $data): string
    {
        if (!preg_match('/^[a-f0-9]{32}\z/',$data['id']??'')) { throw new Failure('state'); }
        return $this->runtime->directory() . '/operations/' . $data['id'];
    }
    public function locked(\Closure $work): mixed
    {
        Files::mkdir($this->runtime->directory()); $path=$this->runtime->directory() . '/worker.lock';
        if (is_link($path)) { throw new Failure('storage'); }
        $lock=fopen($path,'c'); if (!$lock) { throw new Failure('storage'); } chmod($path,0600);
        try { if (!flock($lock,LOCK_EX|LOCK_NB)) { throw new Failure('busy'); } return $work(); }
        finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    public function publicStatus(): array
    {
        $data=$this->load();
        if ($data===null) { return ['phase'=>'idle']; }
        $result=array_intersect_key($data,array_flip(['id','version','phase','error','downloaded','size','extracted','entries','backup_rows','backup_table','backup_tables','failed_phase']));
        if ($data['phase']==='verify') { $result['probe_url']='/_update-probe/' . $data['probe']; }
        return $result;
    }
}
