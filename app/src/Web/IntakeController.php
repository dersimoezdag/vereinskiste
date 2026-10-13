<?php
declare(strict_types=1);
namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Intake\MailboxSettings;
use Vereinskiste\Intake\MailIntake;
use Vereinskiste\Intake\Sandbox;
use Vereinskiste\Invoices\Payments;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Ledger\ValidationError;

final class IntakeController extends AdminController
{
    public function __construct(private Closure $pdo,private MailboxSettings $settings,private string $directory) {}
    public function index(Request $req,Response $res): Response
    {
        $pdo=($this->pdo)();
        $messages=$pdo->query('SELECT r.*,m.name AS member_name FROM receipt_mail_messages r LEFT JOIN members m ON m.id=r.suggested_member_id ORDER BY r.id DESC LIMIT 100')->fetchAll();
        $documents=$pdo->query("SELECT d.*,f.original_name FROM invoice_documents d JOIN stored_files f ON f.id=d.file_id WHERE security_status IN ('pending','error','blocked') ORDER BY d.id DESC LIMIT 100")->fetchAll();
        return $req->getAttribute('view')->render($res,'intake/index.twig',['messages'=>$messages,'documents'=>$documents]);
    }
    public function settings(Request $req,Response $res): Response {return $this->renderSettings($req,$res);}
    public function saveSettings(Request $req,Response $res): Response
    {
        $body=$this->body($req);
        try {
            if(($body['enabled']??'')==='1') {(new MailIntake(($this->pdo)(),$this->directory))->validateActor(Fields::version($body['actor_id']??null));}
            $this->settings->save($body,Fields::version($body['version']??null));
        } catch(ValidationError $e) {return $this->renderSettings($req,$res->withStatus(422),$e->errors);}
        catch(Conflict) {return $this->renderSettings($req,$res->withStatus(409),['version'=>'foundation.conflict']);}
        return $this->redirect('/settings/receipt-mailbox');
    }
    public function retry(Request $req,Response $res,array $args): Response
    {
        $pdo=($this->pdo)();$id=(int)$args['id'];
        $s=$pdo->prepare("SELECT id FROM invoice_documents WHERE id=? AND security_status='error'");$s->execute([$id]);
        if(!$s->fetchColumn()) {return $this->notFound($req);}
        (new \Vereinskiste\Jobs\Queue($pdo))->enqueue('invoice_extract',['document_id'=>$id],$id.':retry:'.bin2hex(random_bytes(8)),20);
        return $this->redirect('/receipt-inbox');
    }
    public function payment(Request $req,Response $res,array $args): Response
    {
        $id=(int)$args['id'];$body=$this->body($req);$p=new Payments(($this->pdo)());$actor=$this->userId($req);$version=Fields::version($body['version']??null);
        try {
            $action=$this->str($body,'action');$reason=$this->str($body,'reason');
            if($action==='save') {$p->save($id,$body,$version,$actor);}
            elseif($action==='approve'||$action==='reject') {
                if(!$req->getAttribute('auth')->can('payments.approve')) {return $res->withStatus(403);}
                $p->approve($id,$version,$reason,$actor,$action==='reject');
            } elseif(in_array($action,['allocate','unlink'],true)) {
                if(!$req->getAttribute('auth')->can('bookings.write')) {return $res->withStatus(403);}
                $booking=Fields::version($body['booking_id']??null);
                if($action==='allocate') {$p->allocate($id,$booking,$version,$reason,$actor);} else {$p->unlink($id,$booking,$version,$reason,$actor);}
            } else {throw new ValidationError(['payment'=>'foundation.invalid']);}
        } catch(ValidationError $e) {return \Vereinskiste\Http\ErrorPage::render($req->getAttribute('view'),422,'intake.payment.title',array_values($e->errors)[0],'/invoices/'.$id);}
        catch(Conflict) {return \Vereinskiste\Http\ErrorPage::render($req->getAttribute('view'),409,'intake.payment.title','foundation.conflict','/invoices/'.$id);}
        return $this->redirect('/invoices/'.$id);
    }
    private function renderSettings(Request $req,Response $res,array $errors=[]): Response
    {
        $state=$this->settings->get();$config=$state['config'];$hasPassword=$config['password']!=='';unset($config['password']);
        $users=($this->pdo)()->query("SELECT u.id,u.name FROM users u WHERE active AND EXISTS(SELECT 1 FROM user_roles r WHERE r.user_id=u.id AND role IN ('admin','vorstand','kassenwart')) ORDER BY lower(name)")->fetchAll();
        return $req->getAttribute('view')->render($res,'intake/settings.twig',['input'=>$config,'version'=>$state['version'],'has_password'=>$hasPassword,'errors'=>$errors,'users'=>$users,'imap_available'=>function_exists('imap_open'),'processing_available'=>Sandbox::available()]);
    }
}
