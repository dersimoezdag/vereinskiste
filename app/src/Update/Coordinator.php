<?php
declare(strict_types=1);
namespace Vereinskiste\Update;

use PDO;
use Vereinskiste\Audit\AuditLog;

final class Coordinator
{
    public const BARRIER=71192314;
    public const TERMINAL=['done','failed','cancelled','rolled_back'];
    private State $state;
    public function __construct(private PDO $db,private Runtime $runtime,private ?HttpClient $http=null)
    {
        $this->state=new State($runtime); $this->http??=new HttpClient();
    }
    public function start(array $release,string $installed,int $actor): void
    {
        $this->state->locked(function() use($release,$installed,$actor): void {
            $old=$this->state->load();
            if ($old!==null && !in_array($old['phase'],self::TERMINAL,true)) { throw new Failure('busy'); }
            if (!Requirements::supported($this->runtime)) { throw new Failure('requirements'); }
            $current=$this->runtime->active()['version']??$installed;
            if (($release['status']??'')!=='available' || !isset($release['download'],$release['manifest'],$release['signature'])
                || !preg_match('/^\d+\.\d+\.\d+\z/',$release['version']??'') || !version_compare($release['version'],$installed,'>')) { throw new Failure('signed_release'); }
            if (!version_compare($release['version'],$current,'>')) { throw new Failure('signed_release'); }
            $base=\Vereinskiste\Install\GitHubReleases::REPOSITORY . '/releases/download/';
            foreach (['download','manifest','signature'] as $key) {
                if (!str_starts_with($release[$key],$base)) { throw new Failure('download_url'); }
            }
            $data=['id'=>bin2hex(random_bytes(16)),'version'=>$release['version'],'source_version'=>$installed,'actor'=>$actor,
                'phase'=>'manifest','previous'=>$this->runtime->active(),'probe'=>bin2hex(random_bytes(32)),
                'urls'=>['package'=>$release['download'],'manifest'=>$release['manifest'],'signature'=>$release['signature']]];
            Files::mkdir($this->state->path($data)); $this->state->save($data);
            (new AuditLog($this->db))->record('auto_update_started',$actor,['operation'=>$data['id'],'version'=>$data['version']]);
        });
    }
    public function step(): array
    {
        return $this->state->locked(function(): array {
            $data=$this->state->load();
            if ($data===null || in_array($data['phase'],self::TERMINAL,true)) {
                if ($data!==null && in_array($data['phase'],['done','rolled_back'],true) && ($this->runtime->maintenance()['id']??null)===$data['id']) {
                    unlink($this->runtime->directory() . '/maintenance.json');
                }
                return $this->state->publicStatus();
            }
            $exclusive=false;
            try {
                if (in_array($data['phase'],['manifest','signature','download','inspect','extract'],true)) {
                    $data=(new Transfer($this->runtime,$this->state,$this->http))->step($data);
                } else {
                    if ($this->runtime->maintenance()===null) {
                        Files::json($this->runtime->directory() . '/maintenance.json',array_intersect_key($data,array_flip(['id','previous','probe'])));
                    }
                    $exclusive=(bool)$this->db->query('SELECT pg_try_advisory_lock(' . self::BARRIER . ')')->fetchColumn();
                    if (!$exclusive) { return $this->state->publicStatus(); }
                    $activation=new Activation($this->db,$this->runtime,$this->state);
                    switch ($data['phase']) {
                        case 'waiting':
                            $data['backup']=(new DatabaseBackup($this->db,$this->state->path($data) . '/backup'))->start();
                            $data['phase']='backup'; break;
                        case 'backup':
                            $data['backup']=(new DatabaseBackup($this->db,$this->state->path($data) . '/backup'))->step($data['backup']);
                            $data['backup_rows']=$data['backup']['rows'];
                            if ($data['backup']['done']??false) { $data['phase']='activate'; }
                            break;
                        case 'activate': $data=$activation->apply($data); break;
                        case 'verify': $data=$activation->verify($data); break;
                        case 'rollback': $data=$activation->rollback($data); break;
                        case 'recovery_required': $data=$activation->rollback($data); break;
                    }
                }
            } catch (\Throwable $error) {
                $data['error']=$error instanceof Failure?$error->reason:'update';
                $data['failed_phase']??=$data['phase'];
                $data['phase']=$data['phase']==='rollback' || $data['phase']==='recovery_required' ? 'recovery_required'
                    : ($this->runtime->maintenance()===null?'failed':'rollback');
            } finally { if ($exclusive) { $this->db->query('SELECT pg_advisory_unlock(' . self::BARRIER . ')'); } }
            $this->state->save($data); return $this->state->publicStatus();
        });
    }
    public function cancel(): void
    {
        $this->state->locked(function(): void {
            $data=$this->state->load(); if ($data===null || in_array($data['phase'],self::TERMINAL,true)) { return; }
            $data['failed_phase']??=$data['phase'];
            $data['phase']=$this->runtime->maintenance()===null?'cancelled':'rollback'; $this->state->save($data);
        });
    }
}
