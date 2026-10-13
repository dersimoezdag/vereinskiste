<?php
declare(strict_types=1);
namespace Vereinskiste\Privacy;

use PDO;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Ledger\Transaction;

final class Consents
{
    public const KINDS=['statute','privacy','media','newsletter','sepa'];
    public function __construct(private PDO $pdo) {}
    public function snapshot(string $kind): array
    {
        $settings=(new Settings($this->pdo))->get()['data'];
        $profile=(new \Vereinskiste\Club\Profile($this->pdo))->get()['data'];
        $texts=require dirname(__DIR__).'/Text/de.php';
        $key=match($kind){'statute'=>'applications.consent_statute','privacy'=>'applications.consent_privacy','sepa'=>'applications.sepa.mandate_text',default=>'privacy.consent.'.$kind};
        return ['version'=>$settings[$kind.'_version']??'', 'url'=>$profile[match($kind){'statute'=>'constitution_url','privacy'=>'privacy_url',default=>'unused'}]??'',
            'text'=>$texts[$key]??$key];
    }
    public function fingerprint(): string
    {
        return hash('sha256',json_encode([$this->snapshot('statute'),$this->snapshot('privacy'),$this->snapshot('sepa')],JSON_THROW_ON_ERROR));
    }
    public function fromApplication(int $application,array $input): void
    {
        foreach(['statute'=>'consent_statute','privacy'=>'consent_privacy','sepa'=>'sepa_mandate_consent'] as $kind=>$field) {
            if(!empty($input[$field])) { $this->append(null,$application,$kind,true,$this->snapshot($kind),'application',null,''); }
        }
    }
    public function record(int $member,array $input,int $actor): void
    {
        $data=Fields::read($input,['kind'=>20,'document_version'=>100,'reason'=>500],['kind','reason']);
        if(!in_array($data['kind'],self::KINDS,true)||!in_array($input['granted']??null,['0','1'],true)) {
            throw new ValidationError(['kind'=>'foundation.invalid']);
        }
        Transaction::run($this->pdo,function()use($member,$data,$input,$actor):void {
            $stmt=$this->pdo->prepare('SELECT anonymized_at FROM members WHERE id=? FOR UPDATE');$stmt->execute([$member]);$row=$stmt->fetch();
            if(!$row||$row['anonymized_at']!==null) { throw new ValidationError(['member'=>'foundation.invalid']); }
            $snapshot=$this->snapshot($data['kind']);$snapshot['version']=$data['document_version'];
            $this->append($member,null,$data['kind'],$input['granted']==='1',$snapshot,'staff',$actor,$data['reason']);
        });
    }
    public function list(int $member): array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM member_consents WHERE member_id=? ORDER BY id DESC');$stmt->execute([$member]);
        return array_map(static function(array $r):array {$r['snapshot']=json_decode($r['snapshot'],true,512,JSON_THROW_ON_ERROR);return $r;},$stmt->fetchAll());
    }
    private function append(?int $member,?int $application,string $kind,bool $granted,array $snapshot,string $source,?int $actor,string $reason):void
    {
        $json=json_encode($snapshot,JSON_THROW_ON_ERROR);
        $this->pdo->prepare('INSERT INTO member_consents(member_id,application_id,kind,granted,document_version,snapshot,snapshot_hash,source,recorded_by,reason) VALUES(?,?,?,?,?,?::jsonb,?,?,?,?)')
            ->execute([$member,$application,$kind,$granted?'true':'false',$snapshot['version'],$json,hash('sha256',$json),$source,$actor,$reason]);
    }
}
