<?php
declare(strict_types=1);
namespace Vereinskiste\Privacy;

use PDO;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

final class ApplicationPrivacy
{
    public function __construct(private PDO $pdo) {}
    public function preview(int $id,bool $lock=false):array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM membership_applications WHERE id=?'.($lock?' FOR UPDATE':''));$stmt->execute([$id]);$app=$stmt->fetch();
        if(!$app) {throw new ValidationError(['application'=>'foundation.invalid']);}
        if($lock&&($app['status']!=='rejected'||$app['member_id']!==null)) {throw new ValidationError(['application'=>'privacy.block.application']);}
        $policy=(new Settings($this->pdo))->get($lock);$blocked=[];
        if($app['status']!=='rejected'||$app['member_id']!==null) {$blocked[]='privacy.block.application';}
        if($app['anonymized_at']!==null) {$blocked[]='privacy.block.already';}
        $days=$policy['data']['application_retention_days'];if($days==='') {$blocked[]='privacy.application.policy';}
        $eligible=(new \DateTimeImmutable($app['reviewed_at']??$app['created_at']))->modify('+'.(int)$days.' days')->format('Y-m-d');
        if($eligible>(new \DateTimeImmutable('today'))->format('Y-m-d')) {$blocked[]='privacy.block.retention';}
        $stmt=$this->pdo->prepare('SELECT id,status FROM mail_outbox WHERE application_id=?'.($lock?' FOR UPDATE':''));$stmt->execute([$id]);$messages=$stmt->fetchAll();
        if(in_array('sending',array_column($messages,'status'),true)) {$blocked[]='privacy.block.sending';}
        return ['application'=>$app,'blocked'=>$blocked,'eligible'=>$eligible,'fingerprint'=>hash('sha256',json_encode([$app['version'],$policy['version'],array_column($messages,'id')],JSON_THROW_ON_ERROR))];
    }
    public function anonymize(int $id,int $version,string $fingerprint,string $reason,int $actor):void
    {
        $reason=Fields::read(['reason'=>$reason],['reason'=>500],['reason'])['reason'];
        Transaction::run($this->pdo,function()use($id,$version,$fingerprint,$reason,$actor):void {
            $preview=$this->preview($id,true);
            if((int)$preview['application']['version']!==$version||!hash_equals($preview['fingerprint'],$fingerprint)) {throw new Conflict();}
            if($preview['blocked']!==[]) {throw new ValidationError(['application'=>$preview['blocked'][0]]);}
            $this->redact($id,'Anonymisierter Antrag #'.$id);
            $this->pdo->prepare("INSERT INTO privacy_actions(application_id,actor_id,action,reason) VALUES(?,?,'anonymized',?)")->execute([$id,$actor,$reason]);
            (new \Vereinskiste\Audit\AuditLog($this->pdo))->record('application_anonymized',$actor,['application_id'=>$id]);
        });
    }
    /** Called only from a locked privacy transaction. */
    public function redact(int $id,string $name):void
    {
        if(!$this->pdo->inTransaction()) {throw new \LogicException('Redaction requires a transaction.');}
        $this->pdo->prepare("UPDATE membership_applications SET applicant_name=?,email='redacted@example.invalid',data='{}'::jsonb,rejection_reason=NULL,anonymized_at=now(),version=version+1 WHERE id=?")->execute([$name,$id]);
        $this->pdo->prepare("UPDATE mail_outbox SET recipient='redacted@example.invalid',subject='',body='',status=CASE WHEN status='sent' THEN 'sent' ELSE 'cancelled' END,version=version+1 WHERE application_id=?")->execute([$id]);
        $this->pdo->prepare("UPDATE member_consents SET snapshot='{}'::jsonb,document_version='',reason='privacy.redacted',snapshot_hash=? WHERE application_id=? AND member_id IS NULL")->execute([hash('sha256','{}'),$id]);
        $this->pdo->prepare("UPDATE membership_application_invites SET title='',password_hash=NULL,expires_at=now() WHERE id IN(SELECT invite_id FROM membership_applications WHERE id=?)")->execute([$id]);
    }
}
