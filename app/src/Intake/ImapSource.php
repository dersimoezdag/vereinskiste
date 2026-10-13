<?php
declare(strict_types=1);
namespace Vereinskiste\Intake;

/** Read-only IMAPS, certificate validation mandatory; no deletion or marking Seen. */
final class ImapSource
{
    private mixed $connection;
    private string $mailbox;
    public function __construct(array $config)
    {
        if (!function_exists('imap_open')) { throw new \RuntimeException('IMAP unavailable'); }
        foreach ([IMAP_OPENTIMEOUT,IMAP_READTIMEOUT,IMAP_WRITETIMEOUT,IMAP_CLOSETIMEOUT] as $type) { imap_timeout($type,10); }
        $this->mailbox = '{'.$config['host'].':993/imap/ssl/validate-cert}'.$config['folder'];
        $this->connection = @imap_open($this->mailbox,$config['username'],$config['password'],OP_READONLY,1,['DISABLE_AUTHENTICATOR'=>'GSSAPI']);
        if (!$this->connection) { imap_errors(); imap_alerts(); throw new \RuntimeException('IMAP connection unavailable'); }
        if (imap_num_msg($this->connection)>20000) { $this->close(); throw new \RuntimeException('Use a dedicated receipt folder'); }
    }
    public function validity(): int
    {
        $status = imap_status($this->connection,$this->mailbox,SA_UIDVALIDITY);
        if (!$status || empty($status->uidvalidity)) { throw new \RuntimeException('IMAP status unavailable'); }
        return (int)$status->uidvalidity;
    }
    public function uids(int $after): array
    {
        $uids = imap_search($this->connection,'UID '.max(1,$after+1).':*',SE_UID) ?: [];
        $uids = array_values(array_filter($uids,static fn(int $uid):bool=>$uid>$after));sort($uids);
        return array_slice($uids,0,5);
    }
    public function message(int $uid): array
    {
        $overview = imap_fetch_overview($this->connection,(string)$uid,FT_UID);
        if (!$overview) { throw new \RuntimeException('Message unavailable'); }
        $o = $overview[0];
        if (($o->size??PHP_INT_MAX)>20971520) { throw new \DomainException('message_limit'); }
        $structure = imap_fetchstructure($this->connection,$uid,FT_UID);
        if (!$structure) { throw new \RuntimeException('Message unavailable'); }
        $parts = MimeParts::flatten($structure);
        $hasPlain = count(array_filter($parts,static fn(array $p):bool=>$p['text']&&!$p['html']))>0;
        $files = [];$body = '';$bytes = 0;
        foreach ($parts as $part) {
            if ($part['html'] && $hasPlain) { continue; }
            $raw = imap_fetchbody($this->connection,$uid,$part['section'],FT_UID|FT_PEEK);
            if ($raw===false) { throw new \RuntimeException('Message unavailable'); }
            $data = MimeParts::decode($raw,$part['encoding']);
            $bytes += strlen($data);if($bytes>20971520) {throw new \DomainException('message_limit');}
            if ($part['text']) {
                try { $body .= mb_convert_encoding($data,'UTF-8',$part['charset']); } catch (\ValueError) { $body .= mb_convert_encoding($data,'UTF-8','UTF-8'); }
            } else { $files[] = ['name'=>$part['name'],'data'=>$data]; }
        }
        if ($files===[] || count($files)>8) { throw new \DomainException('attachment_limit'); }
        $addresses = imap_rfc822_parse_adrlist($o->from??'','');$sender = '';
        if (count($addresses)===1 && isset($addresses[0]->mailbox,$addresses[0]->host)) { $sender = $addresses[0]->mailbox.'@'.$addresses[0]->host; }
        return ['sender'=>$sender,'subject'=>mb_decode_mimeheader($o->subject??''),'body'=>$body,'files'=>$files];
    }
    public function close(): void
    {
        if ($this->connection) { @imap_close($this->connection);$this->connection=null;imap_errors();imap_alerts(); }
    }
    public function __destruct() { $this->close(); }
}
