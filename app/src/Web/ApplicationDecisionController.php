<?php
declare(strict_types=1);
namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Members\ApplicationRepository;
use Vereinskiste\Members\ApplicationNotifications;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Ledger\ValidationError;

final class ApplicationDecisionController extends AdminController
{
    public function __construct(private Closure $pdo) {}
    public function show(Request $req,Response $res,array $args):Response {return $this->page($req,$res,(int)$args['id']);}
    public function save(Request $req,Response $res,array $args):Response
    {
        $id=(int)$args['id'];$repo=new ApplicationRepository(($this->pdo)());$app=$repo->findApplication($id);
        if(!$app) {return $this->notFound($req);} $input=$this->body($req);
        try {
            $mail=Fields::read($input,['subject'=>200,'message'=>10000,'reason'=>500],['subject','message']);
            if(!in_array($input['decision']??null,['approved','rejected'],true)) {throw new ValidationError(['decision'=>'foundation.invalid']);}
            if($app['status']!=='pending') {throw new ValidationError(['application'=>'foundation.invalid']);}
            if(($input['stage']??'')==='edit') {return $this->page($req,$res,$id,$input);}
            if(($input['stage']??'')!=='confirm') {return $this->page($req,$res,$id,$input,[],true);}
            if(($input['decision']??'')==='approved') {$repo->approve($id,$this->userId($req),$mail);}
            else {$repo->reject($id,$mail['reason'],$this->userId($req),$mail);}
        } catch(ValidationError $e) {return $this->page($req,$res->withStatus(422),$id,$input,$e->errors);}
        $this->flash($req,'foundation.saved');return $this->redirect('/members/applications');
    }
    public function retry(Request $req,Response $res,array $args):Response
    {
        $input=$this->body($req);
        try {(new \Vereinskiste\Mail\Outbox(($this->pdo)()))->retry((int)$args['id'],Fields::version($input['version']??null),$this->userId($req));}
        catch(\Vereinskiste\Foundation\Conflict) {return \Vereinskiste\Http\ErrorPage::render($req->getAttribute('view'),409,'applications.title','foundation.conflict','/members/applications');}
        $this->flash($req,'foundation.saved');return $this->redirect('/members/applications');
    }
    private function page(Request $req,Response $res,int $id,?array $input=null,array $errors=[],bool $preview=false):Response
    {
        $app=(new ApplicationRepository(($this->pdo)()))->findApplication($id);if(!$app) {return $this->notFound($req);}
        $decision=$input['decision']??($req->getQueryParams()['decision']??'approved');
        if(!in_array($decision,['approved','rejected'],true)) {$decision='approved';}
        $values=$input??((new ApplicationNotifications(($this->pdo)()))->defaults($decision)+['reason'=>'','decision'=>$decision]);$safe=[];
        foreach($values as $key=>$value) {$safe[$key]=is_string($value)&&mb_check_encoding($value,'UTF-8')?$value:'';}
        return $req->getAttribute('view')->render($res,'members/decision.twig',['application'=>$app,'input'=>$safe,'errors'=>$errors,'preview'=>$preview,'mail_configured'=>$req->getAttribute('mail_configured',false)]);
    }
}
