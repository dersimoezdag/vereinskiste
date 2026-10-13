<?php
declare(strict_types=1);
namespace Vereinskiste\Mail;

use PDO;
use Throwable;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

/** SMTP is only invoked by cron. Ambiguous deliveries require an explicit retry. */
final class Outbox
{
    public function __construct(private PDO $pdo) {}
    public function add(int $application,string $kind,string $recipient,string $subject,string $body):void
    {
        if(!filter_var($recipient,FILTER_VALIDATE_EMAIL)||strlen($recipient)>254||preg_match('/[\r\n]/',$subject)||mb_strlen($subject)>200||mb_strlen($body)>10000) {
            throw new ValidationError(['message'=>'foundation.invalid']);
        }
        $this->pdo->prepare('INSERT INTO mail_outbox(application_id,kind,recipient,subject,body,dedup_key) VALUES(?,?,?,?,?,?) ON CONFLICT(dedup_key) DO NOTHING')
            ->execute([$application,$kind,$recipient,$subject,$body,hash('sha256',$application.':'.$kind.':'.strtolower($recipient))]);
    }
    public function list(?int $application=null):array
    {
        $stmt=$this->pdo->prepare('SELECT id,application_id,kind,recipient,subject,status,version,attempts,created_at,sent_at FROM mail_outbox WHERE (?::bigint IS NULL OR application_id=?) ORDER BY id DESC LIMIT 100');
        $stmt->execute([$application,$application]);return $stmt->fetchAll();
    }
    public function retry(int $id,int $version,int $actor):void
    {
        Transaction::run($this->pdo,function()use($id,$version,$actor):void {
            $stmt=$this->pdo->prepare("UPDATE mail_outbox SET status='pending',version=version+1 WHERE id=? AND version=? AND status IN ('failed','uncertain') RETURNING id");
            $stmt->execute([$id,$version]);if($stmt->fetchColumn()===false) { throw new Conflict(); }
            (new \Vereinskiste\Audit\AuditLog($this->pdo))->record('application_mail_retried',$actor,['outbox_id'=>$id]);
        });
    }
    public function process(Mailer $mailer):bool
    {
        // Do not silently resend a message after a worker crash or an ambiguous SMTP failure.
        $this->pdo->exec("UPDATE mail_outbox SET status='uncertain',version=version+1 WHERE status='sending' AND attempted_at<now()-interval '5 minutes'");
        if(!$mailer->isConfigured()) { return false; }
        $row=Transaction::run($this->pdo,function():array|false {
            $stmt=$this->pdo->query("SELECT * FROM mail_outbox WHERE status='pending' ORDER BY id FOR UPDATE SKIP LOCKED LIMIT 1");
            $row=$stmt->fetch();if(!$row) { return false; }
            $this->pdo->prepare("UPDATE mail_outbox SET status='sending',attempts=attempts+1,attempted_at=now(),version=version+1 WHERE id=?")->execute([$row['id']]);
            return $row;
        });
        if(!$row) { return false; }
        try {
            $mailer->send($row['recipient'],$row['subject'],$row['body']);
            $this->pdo->prepare("UPDATE mail_outbox SET status='sent',sent_at=now(),version=version+1 WHERE id=? AND status='sending'")->execute([$row['id']]);
        } catch(Throwable) {
            $this->pdo->prepare("UPDATE mail_outbox SET status='uncertain',version=version+1 WHERE id=? AND status='sending'")->execute([$row['id']]);
        }
        return true;
    }
}
