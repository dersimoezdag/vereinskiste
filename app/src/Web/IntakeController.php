<?php
declare(strict_types=1);
namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Invoices\Payments;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Ledger\ValidationError;

final class IntakeController extends AdminController
{
    public function __construct(private Closure $pdo,private string $directory) {}
    public function index(Request $req,Response $res): Response
    {
        $pdo=($this->pdo)();
        $documents=$pdo->query("SELECT d.*,f.original_name FROM invoice_documents d JOIN stored_files f ON f.id=d.file_id JOIN invoices i ON i.id=d.invoice_id WHERE i.status='draft' ORDER BY d.id DESC LIMIT 100")->fetchAll();
        return $req->getAttribute('view')->render($res,'intake/index.twig',['documents'=>$documents]);
    }
    public function paymentSettings(Request $req,Response $res): Response {return $this->renderPaymentSettings($req,$res);}
    public function savePaymentSettings(Request $req,Response $res): Response
    {
        $input=$this->body($req);
        try {(new \Vereinskiste\Invoices\PaymentPolicy(($this->pdo)()))->save(($input['four_eyes']??'')==='1',Fields::version($input['version']??null),$this->userId($req),$this->str($input,'reason'));}
        catch(ValidationError $e) {return $this->renderPaymentSettings($req,$res->withStatus(422),$e->errors);}
        catch(Conflict) {return $this->renderPaymentSettings($req,$res->withStatus(409),['version'=>'foundation.conflict']);}
        return $this->redirect('/settings/payments');
    }
    private function renderPaymentSettings(Request $req,Response $res,array $errors=[]): Response
    {
        $pdo=($this->pdo)();return $req->getAttribute('view')->render($res,'intake/payment-settings.twig',[
            'policy'=>(new \Vereinskiste\Invoices\PaymentPolicy($pdo))->get(),'errors'=>$errors,'history'=>(new \Vereinskiste\Foundation\History($pdo))->for('payment_policy',1)]);
    }
    public function ocr(Request $req,Response $res,array $args): Response
    {
        try {(new \Vereinskiste\Invoices\Extraction(($this->pdo)(),[]))->saveOcr((int)$args['id'],(int)$args['document'],$this->str($this->body($req),'text'));}
        catch(ValidationError) {$res->getBody()->write('{"error":"OCR-Ergebnis konnte nicht gespeichert werden. Bitte Seite neu laden."}');return $res->withStatus(422)->withHeader('Content-Type','application/json');}
        $res->getBody()->write('{"ok":true}');return $res->withHeader('Content-Type','application/json');
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
}
