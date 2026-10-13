<?php
declare(strict_types=1);
namespace Vereinskiste\Intake;

use PDO;
use Vereinskiste\Auth\Permissions;
use Vereinskiste\Invoices\Import;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

final class MailIntake
{
    public function __construct(private PDO $pdo,private string $directory) {}
    public function poll(array $config): void
    {
        if (empty($config['enabled'])) { return; }
        $this->validateActor((int)$config['actor_id']);
        $source = new ImapSource($config);$key = MailboxSettings::key($config);$validity = $source->validity();
        $stmt = $this->pdo->prepare('SELECT COALESCE(max(uid),0) FROM receipt_mail_messages WHERE mailbox_key=? AND uid_validity=?');
        $stmt->execute([$key,$validity]);
        foreach ($source->uids((int)$stmt->fetchColumn()) as $uid) {
            try { $message = $source->message($uid);$this->receive($key,$validity,$uid,$message,(int)$config['actor_id']); }
            catch (\DomainException) { $this->reject($key,$validity,$uid,'message_rejected'); }
            catch (ValidationError) { $this->reject($key,$validity,$uid,'invalid_or_duplicate_attachment'); }
            // Transport or infrastructure failures stop here: do not advance past a failed UID.
        }
    }
    public function validateActor(int $id): void
    {
        $stmt = $this->pdo->prepare('SELECT role FROM user_roles JOIN users ON users.id=user_id WHERE users.id=? AND users.active');
        $stmt->execute([$id]);
        if (!in_array('invoices.write',Permissions::for($stmt->fetchAll(PDO::FETCH_COLUMN)),true)) { throw new ValidationError(['actor_id'=>'foundation.invalid']); }
    }
    public function receive(string $key,int $validity,int $uid,array $message,int $actor): ?int
    {
        $this->validateActor($actor);
        if (count($message['files'])<1 || count($message['files'])>8) { throw new \DomainException('attachment_limit'); }
        return Transaction::run($this->pdo,function() use($key,$validity,$uid,$message,$actor):?int {
            $stmt = $this->pdo->prepare("INSERT INTO receipt_mail_messages(mailbox_key,uid_validity,uid,sender,subject,body,status) VALUES(?,?,?,?,?,?,'received') ON CONFLICT DO NOTHING RETURNING id");
            $sender = self::text($message['sender'],254);
            $stmt->execute([$key,$validity,$uid,$sender,self::text($message['subject'],500),self::text($message['body'],20000)]);
            $id = $stmt->fetchColumn();if (!$id) { return null; }
            $match = $this->pdo->prepare("SELECT id FROM members WHERE anonymized_at IS NULL AND lower(data->>'email')=lower(?) LIMIT 2");
            $match->execute([$sender]);$members = $match->fetchAll(PDO::FETCH_COLUMN);
            $invoice = null;
            foreach ($message['files'] as $file) {
                if (strlen($file['data'])>10485760) { throw new \DomainException('attachment_limit'); }
                $temp = tempnam(sys_get_temp_dir(),'vk-incoming-');
                if (!$temp || !chmod($temp,0600)) { throw new \RuntimeException('Storage unavailable'); }
                try {
                    if (file_put_contents($temp,$file['data'])!==strlen($file['data'])) { throw new \RuntimeException('Storage unavailable'); }
                    $invoice = (new Import($this->pdo,$this->directory))->upload($temp,$file['name'],$actor,$invoice);
                } finally { unlink($temp); }
            }
            $this->pdo->prepare("UPDATE receipt_mail_messages SET invoice_id=?,suggested_member_id=?,status='imported' WHERE id=?")
                ->execute([$invoice,count($members)===1?$members[0]:null,$id]);
            return $invoice;
        });
    }
    private function reject(string $key,int $validity,int $uid,string $code): void
    {
        $this->pdo->prepare("INSERT INTO receipt_mail_messages(mailbox_key,uid_validity,uid,status,error_code) VALUES(?,?,?,'rejected',?) ON CONFLICT DO NOTHING")
            ->execute([$key,$validity,$uid,$code]);
    }
    private static function text(string $value,int $length): string
    {
        return mb_substr(str_replace("\0",'',mb_convert_encoding($value,'UTF-8','UTF-8')),0,$length);
    }
}
