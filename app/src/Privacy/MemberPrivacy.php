<?php
declare(strict_types=1);
namespace Vereinskiste\Privacy;

use PDO;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\MemberRepository;

final class MemberPrivacy
{
    public function __construct(private PDO $pdo) {}
    public function disclosure(int $id):array
    {
        $member=(new MemberRepository($this->pdo))->find($id);
        if(!$member) { throw new ValidationError(['member'=>'foundation.invalid']); }
        $queries=[
            'consents'=>'SELECT kind,granted,document_version,snapshot,snapshot_hash,source,recorded_at,reason FROM member_consents WHERE member_id=? ORDER BY id',
            'roles'=>'SELECT r.name,r.kind,m.scope,m.start_date,m.end_date FROM member_club_roles m JOIN club_roles r ON r.id=m.role_id WHERE m.member_id=? ORDER BY m.id',
            'applications'=>'SELECT id,status,applicant_name,email,data,rejection_reason,created_at,reviewed_at FROM membership_applications WHERE member_id=? ORDER BY id',
            'history'=>"SELECT at,reason,changes FROM foundation_changes WHERE subject='member' AND subject_id=? ORDER BY id",
            'account'=>'SELECT id,name,email,active FROM users WHERE member_id=?',
        ];
        $result=['generated_at'=>(new \DateTimeImmutable())->format(DATE_ATOM),'member'=>$member];
        foreach($queries as $key=>$sql) {$stmt=$this->pdo->prepare($sql);$stmt->execute([$id]);$result[$key]=$stmt->fetchAll();foreach($result[$key] as &$row) {foreach(['snapshot','changes','data'] as $jsonKey) {if(isset($row[$jsonKey])) {$row[$jsonKey]=json_decode($row[$jsonKey],true,512,JSON_THROW_ON_ERROR);}}}unset($row);}
        return $result;
    }
    public function preview(int $id,bool $lock=false):array
    {
        $member=(new MemberRepository($this->pdo))->find($id,$lock);
        if(!$member) { throw new ValidationError(['member'=>'foundation.invalid']); }
        $policy=(new Settings($this->pdo))->get($lock);
        $blocked=[];
        if($member['anonymized_at']!==null) { $blocked[]='privacy.block.already'; }
        if($member['current']) { $blocked[]='privacy.block.active'; }
        if($member['user_id']!==null) { $blocked[]='privacy.block.account'; }
        $days=$policy['data']['member_retention_days'];
        if($days==='') { $blocked[]='privacy.block.policy'; }
        $lastEnd=null;
        foreach($member['periods'] as $period) {if($period['end_date']!==null&&($lastEnd===null||$period['end_date']>$lastEnd)) {$lastEnd=$period['end_date'];}}
        $base=$lastEnd??(new \DateTimeImmutable($member['created_at']))->format('Y-m-d');
        $eligible=(new \DateTimeImmutable($base))->modify('+'.(int)$days.' days')->format('Y-m-d');
        if($eligible!==null&&$eligible>(new \DateTimeImmutable('today'))->format('Y-m-d')) {$blocked[]='privacy.block.retention';}
        if($lock) {
            $stmt=$this->pdo->prepare('SELECT id FROM membership_applications WHERE member_id=? FOR UPDATE');$stmt->execute([$id]);$stmt->fetchAll();
            $stmt=$this->pdo->prepare('SELECT o.id FROM mail_outbox o JOIN membership_applications a ON a.id=o.application_id WHERE a.member_id=? FOR UPDATE OF o');$stmt->execute([$id]);$stmt->fetchAll();
        }
        $stmt=$this->pdo->prepare("SELECT count(*) FROM mail_outbox o JOIN membership_applications a ON a.id=o.application_id WHERE a.member_id=? AND o.status='sending'");$stmt->execute([$id]);
        if((int)$stmt->fetchColumn()>0) {$blocked[]='privacy.block.sending';}
        $counts=[];
        foreach(['history'=>"SELECT count(*) FROM foundation_changes WHERE subject='member' AND subject_id=?",'consents'=>'SELECT count(*) FROM member_consents WHERE member_id=?','applications'=>'SELECT count(*) FROM membership_applications WHERE member_id=?'] as $key=>$sql) {
            $stmt=$this->pdo->prepare($sql);$stmt->execute([$id]);$counts[$key]=(int)$stmt->fetchColumn();
        }
        return ['member'=>$member,'blocked'=>$blocked,'eligible'=>$eligible,'counts'=>$counts,
            'fingerprint'=>hash('sha256',json_encode([$member['version'],$policy['version'],$counts],JSON_THROW_ON_ERROR))];
    }
    public function anonymize(int $id,int $version,string $fingerprint,string $reason,int $actor):void
    {
        $reason=Fields::read(['reason'=>$reason],['reason'=>500],['reason'])['reason'];
        Transaction::run($this->pdo,function()use($id,$version,$fingerprint,$reason,$actor):void {
            $preview=$this->preview($id,true);
            if($preview['member']['version']!==$version||!hash_equals($preview['fingerprint'],$fingerprint)) {throw new Conflict();}
            if($preview['blocked']!==[]) {throw new ValidationError(['member'=>$preview['blocked'][0]]);}
            $name='Anonymisiertes Mitglied #'.$preview['member']['member_no'];
            $this->pdo->prepare("UPDATE members SET name=?,data='{}'::jsonb,anonymized_at=now(),version=version+1 WHERE id=?")->execute([$name,$id]);
            $this->pdo->prepare("UPDATE foundation_changes SET changes='[]'::jsonb,reason='privacy.redacted' WHERE subject='member' AND subject_id=?")->execute([$id]);
            $this->pdo->prepare("UPDATE membership_periods SET end_reason=NULL WHERE member_id=?")->execute([$id]);
            $this->pdo->prepare('UPDATE member_club_roles SET scope=NULL,end_date=COALESCE(end_date,GREATEST(COALESCE(start_date,CURRENT_DATE),CURRENT_DATE)) WHERE member_id=?')->execute([$id]);
            $this->pdo->prepare("UPDATE member_consents SET snapshot='{}'::jsonb,document_version='',reason='privacy.redacted',snapshot_hash=? WHERE member_id=?")->execute([hash('sha256','{}'),$id]);
            $stmt=$this->pdo->prepare('SELECT id FROM membership_applications WHERE member_id=?');$stmt->execute([$id]);
            foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $application) {(new ApplicationPrivacy($this->pdo))->redact((int)$application,$name);}
            $this->pdo->prepare("INSERT INTO privacy_actions(member_id,actor_id,action,reason) VALUES(?,?,'anonymized',?)")->execute([$id,$actor,$reason]);
            (new \Vereinskiste\Audit\AuditLog($this->pdo))->record('member_anonymized',$actor,['member_id'=>$id]);
        });
    }
}
