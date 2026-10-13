<?php
declare(strict_types=1);
namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Invoices\InvoiceRepository;
use Vereinskiste\Invoices\Import;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Receipts\UploadInput;

final class InvoiceController extends AdminController
{
    public function __construct(private Closure $pdo,private string $directory) {}
    public function index(Request $req,Response $res):Response
    {
        $query=$req->getQueryParams();$state=$this->str($query,'state')?:'all';$search=$this->str($query,'q');
        if(!mb_check_encoding($search,'UTF-8')) {$search='';}
        $page=max(1,min(100000,(int)$this->str($query,'page')));
        return $req->getAttribute('view')->render($res,'invoices/index.twig',['invoices'=>(new InvoiceRepository(($this->pdo)()))->list($state,$search,$page),'state'=>$state,'search'=>$search,'page'=>$page]);
    }
    public function create(Request $req,Response $res):Response {return $this->detail($req,$res);}
    public function show(Request $req,Response $res,array $args):Response {return $this->detail($req,$res,(int)$args['id']);}
    public function save(Request $req,Response $res,array $args=[]):Response
    {
        $id=isset($args['id'])?(int)$args['id']:null;$input=$this->body($req);
        if($id!==null&&!(new InvoiceRepository(($this->pdo)()))->find($id)) {return $this->notFound($req);}
        try {$id=(new InvoiceRepository(($this->pdo)()))->save($id,$input,Fields::version($input['version']??null),$this->userId($req),$this->str($input,'reason'));}
        catch(ValidationError $e) {return $this->detail($req,$res->withStatus(422),$id,$input,$e->errors);}
        catch(Conflict) {return $this->detail($req,$res->withStatus(409),$id,$input,['version'=>'foundation.conflict']);}
        $this->flash($req,'foundation.saved');return $this->redirect('/invoices/'.$id);
    }
    public function import(Request $req,Response $res):Response {return $this->importPage($req,$res);}
    public function upload(Request $req,Response $res,array $args=[]):Response
    {
        $id=isset($args['id'])?(int)$args['id']:null;
        try {
            $files=UploadInput::allFromRequest($req);if($files===[]) {throw new ValidationError(['receipt'=>'validation.receipt.none']);}
        } catch(ValidationError $e) {return $id===null?$this->importPage($req,$res->withStatus(422),$e->errors):$this->detail($req,$res->withStatus(422),$id,null,$e->errors);}
        $results=[];
        foreach($files as $file) {
            try {$saved=(new Import(($this->pdo)(),$this->directory))->upload($file['path'],$file['name'],$this->userId($req),$id);$results[]=['name'=>$file['name'],'id'=>$saved];}
            catch(ValidationError $e) {$results[]=['name'=>$file['name'],'error'=>array_values($e->errors)[0]];}
        }
        if($id!==null&&count($results)===1&&isset($results[0]['id'])) {return $this->redirect('/invoices/'.$id);}
        return $this->importPage($req,$res,[],$results);
    }
    public function transition(Request $req,Response $res,array $args):Response
    {
        $id=(int)$args['id'];if(!(new InvoiceRepository(($this->pdo)()))->find($id)) {return $this->notFound($req);}$input=$this->body($req);
        try {(new InvoiceRepository(($this->pdo)()))->transition($id,Fields::version($input['version']??null),$this->str($input,'action'),$this->str($input,'reason'),($input['duplicate_confirmed']??'')==='1',$this->userId($req));}
        catch(ValidationError $e) {return $this->detail($req,$res->withStatus(422),$id,null,$e->errors);}
        catch(Conflict) {return $this->detail($req,$res->withStatus(409),$id,null,['version'=>'foundation.conflict']);}
        $this->flash($req,'foundation.saved');return $this->redirect('/invoices/'.$id);
    }
    private function detail(Request $req,Response $res,?int $id=null,?array $input=null,array $errors=[]):Response
    {
        $repo=new InvoiceRepository(($this->pdo)());$invoice=$id===null?null:$repo->find($id);if($id!==null&&!$invoice) {return $this->notFound($req);}
        $defaults=$invoice??['version'=>0,'currency'=>'EUR','kind'=>'invoice'];$safe=[];
        foreach(($input??$defaults) as $key=>$value) {$safe[$key]=(is_string($value)||is_int($value))&&mb_check_encoding((string)$value,'UTF-8')?(string)$value:'';}
        $contacts=($this->pdo)()->query('SELECT id,name FROM finance_contacts ORDER BY lower(name),id LIMIT 500')->fetchAll();
        return $req->getAttribute('view')->render($res,'invoices/detail.twig',['invoice'=>$invoice,'input'=>$safe,'errors'=>$errors,'fields'=>InvoiceRepository::FIELDS,'contacts'=>$contacts,
            'payment'=>$id===null?null:(new \Vereinskiste\Invoices\Payments(($this->pdo)()))->overview($id),
            'members'=>$req->getAttribute('auth')->can('members.read')?($this->pdo)()->query('SELECT id,name FROM members WHERE anonymized_at IS NULL ORDER BY lower(name) LIMIT 1000')->fetchAll():[],
            'mail_source'=>$id===null?null:$this->mailSource($id),
            'documents'=>$id===null?[]:$repo->documents($id),'duplicates'=>$id===null?[]:$repo->duplicates($invoice,$id),'history'=>$id===null?[]:$repo->historyRows($id)]);
    }
    private function mailSource(int $id): ?array
    {
        $stmt=($this->pdo)()->prepare('SELECT r.*,m.name AS member_name FROM receipt_mail_messages r LEFT JOIN members m ON m.id=r.suggested_member_id WHERE invoice_id=? ORDER BY r.id LIMIT 1');
        $stmt->execute([$id]);return $stmt->fetch()?:null;
    }
    private function importPage(Request $req,Response $res,array $errors=[],array $results=[]):Response
    {return $req->getAttribute('view')->render($res,'invoices/import.twig',['errors'=>$errors,'results'=>$results]);}
}
