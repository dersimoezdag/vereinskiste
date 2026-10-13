<?php
declare(strict_types=1);
namespace Vereinskiste\Privacy;

use PDO;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

final class Settings
{
    public const FIELDS = ['statute_version'=>100,'privacy_version'=>100,'media_version'=>100,'newsletter_version'=>100,
        'sepa_version'=>100,'application_retention_days'=>6,'member_retention_days'=>6,'retention_note'=>2000,'application_mail_to'=>254];
    public function __construct(private PDO $pdo) {}
    public function get(bool $lock=false): array
    {
        $row=$this->pdo->query('SELECT * FROM privacy_settings WHERE id=1' . ($lock?' FOR UPDATE':''))->fetch();
        return ['version'=>(int)$row['version'],'data'=>json_decode($row['data'],true,512,JSON_THROW_ON_ERROR)+array_fill_keys(array_keys(self::FIELDS),'')];
    }
    public function save(array $input,int $version,int $actor): void
    {
        $values=Fields::read($input,self::FIELDS);
        foreach(['member_retention_days','application_retention_days'] as $field) {
            if($values[$field]!==''&&!preg_match('/^(0|[1-9][0-9]{0,5})\z/',$values[$field])) {throw new ValidationError([$field=>'foundation.invalid']);}
        }
        if ($values['application_mail_to']!=='' && !filter_var($values['application_mail_to'],FILTER_VALIDATE_EMAIL)) {
            throw new ValidationError(['application_mail_to'=>'foundation.invalid']);
        }
        Transaction::run($this->pdo,function()use($values,$version,$actor):void {
            if($this->get(true)['version']!==$version) { throw new Conflict(); }
            $this->pdo->prepare('UPDATE privacy_settings SET data=?::jsonb,version=version+1 WHERE id=1')->execute([json_encode($values,JSON_THROW_ON_ERROR)]);
            (new \Vereinskiste\Audit\AuditLog($this->pdo))->record('privacy_settings_updated',$actor);
        });
    }
}
