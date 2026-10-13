<?php
declare(strict_types=1);
namespace Vereinskiste\Members;

use PDO;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Mail\Outbox;

final class ApplicationNotifications
{
    public function __construct(private PDO $pdo) {}
    public function defaults(string $event):array
    {
        $texts=require dirname(__DIR__).'/Text/de.php';
        $club=(new \Vereinskiste\Club\Profile($this->pdo))->name();
        return ['subject'=>$club.' – '.$texts['applications.mail.'.$event.'.subject'],
            'message'=>$texts['applications.mail.'.$event.'.body']."\n\n".$club];
    }
    public function queue(int $id,string $event,?array $custom=null):void
    {
        $app=(new ApplicationRepository($this->pdo))->findApplication($id);
        if(!$app) { return; }
        $mail=$custom===null?$this->defaults($event):Fields::read($custom,['subject'=>200,'message'=>10000],['subject','message']);
        (new Outbox($this->pdo))->add($id,$event,$app['email'],$mail['subject'],$mail['message']);
        if($event==='submitted') {
            $to=(new \Vereinskiste\Privacy\Settings($this->pdo))->get()['data']['application_mail_to'];
            if($to!=='') {
                $internal=$this->defaults('internal');
                (new Outbox($this->pdo))->add($id,'internal',$to,$internal['subject'],$internal['message'].' #'.$id);
            }
        }
    }
}
