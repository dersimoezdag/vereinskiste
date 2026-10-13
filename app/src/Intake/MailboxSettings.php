<?php
declare(strict_types=1);
namespace Vereinskiste\Intake;

use Vereinskiste\Foundation\Fields;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Ledger\ValidationError;

final class MailboxSettings
{
    public const FIELDS = ['host'=>253,'username'=>254,'folder'=>100,'actor_id'=>20];
    public function __construct(private string $directory) {}
    public function get(): array
    {
        $path = $this->directory . '/receipt-mailbox.json';
        return is_file($path) ? json_decode((string) file_get_contents($path),true,512,JSON_THROW_ON_ERROR)
            : ['version'=>0,'config'=>['enabled'=>false,'host'=>'','username'=>'','folder'=>'INBOX','actor_id'=>'','password'=>'']];
    }
    public function save(array $input, int $version): void
    {
        $config = Fields::read($input,self::FIELDS);
        $config['enabled'] = ($input['enabled']??'')==='1';
        if (!preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9.-]{0,251}[a-zA-Z0-9])?$/D',$config['host'])
            || !preg_match('/^[a-zA-Z0-9 _.-]{1,100}$/D',$config['folder'])) { throw new ValidationError(['host'=>'foundation.invalid']); }
        $password = $input['password']??'';
        if (!is_string($password) || strlen($password)>1000 || preg_match('/[\x00\r\n]/',$password)) { throw new ValidationError(['password'=>'foundation.invalid']); }
        if ($config['enabled'] && ($config['username']==='' || !ctype_digit($config['actor_id']))) { throw new ValidationError(['username'=>'foundation.required']); }
        if (!is_dir($this->directory) && !mkdir($this->directory,0700,true)) { throw new \RuntimeException('Settings unavailable'); }
        $lock = fopen($this->directory . '/receipt-mailbox.lock','c');
        if (!$lock) { throw new \RuntimeException('Settings unavailable'); }
        chmod($this->directory . '/receipt-mailbox.lock',0600); $temp = null;
        try {
            if (!flock($lock,LOCK_EX)) { throw new \RuntimeException('Settings unavailable'); }
            $old = $this->get();
            if ($old['version']!==$version) { throw new Conflict(); }
            $config['password'] = ($input['clear_password']??'')==='1' ? '' : ($password!=='' ? $password : $old['config']['password']);
            if ($config['enabled'] && $config['password']==='') { throw new ValidationError(['password'=>'foundation.required']); }
            $temp = tempnam($this->directory,'.receipt-mailbox-');
            if (!$temp || !chmod($temp,0600)) { throw new \RuntimeException('Settings unavailable'); }
            $encoded = json_encode(['version'=>$version+1,'config'=>$config],JSON_THROW_ON_ERROR);
            if (file_put_contents($temp,$encoded)!==strlen($encoded) || !rename($temp,$this->directory.'/receipt-mailbox.json')) { throw new \RuntimeException('Settings unavailable'); }
        } finally { if ($temp && is_file($temp)) { unlink($temp); } flock($lock,LOCK_UN); fclose($lock); }
    }
    public static function key(array $config): string
    {
        return hash('sha256',strtolower($config['host'])."\0".$config['username']."\0".$config['folder']);
    }
}
