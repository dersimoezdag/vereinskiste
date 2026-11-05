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
            if($input['decision']==='approved') {
                $type=$input['membership_type_id']??'';
                if(!is_string($type)||!ctype_digit($type)) {throw new ValidationError(['membership_type_id'=>'foundation.invalid']);}
                $date=is_string($input['join_date']??null)?trim($input['join_date']):'';
                $repo->approve($id,$this->userId($req),$mail,$date,(int)$type);
            } else {$repo->reject($id,$mail['reason'],$this->userId($req),$mail);}
        } catch(ValidationError $e) {return $this->page($req,$res->withStatus(422),$id,$input,$e->errors);}
        $this->flash($req,$input['decision']==='approved'?'applications.approved_success':'applications.rejected_success');return $this->redirect('/members/applications');
    }
    public function retry(Request $req,Response $res,array $args):Response
    {
        $input=$this->body($req);
        try {(new \Vereinskiste\Mail\Outbox(($this->pdo)()))->retry((int)$args['id'],Fields::version($input['version']??null),$this->userId($req));}
        catch(\Vereinskiste\Foundation\Conflict) {return \Vereinskiste\Http\ErrorPage::render($req->getAttribute('view'),409,'applications.title','foundation.conflict','/members/applications?tab=messages');}
        $this->flash($req,'foundation.saved');return $this->redirect('/members/applications?tab=messages');
    }
    private function page(Request $req,Response $res,int $id,?array $input=null,array $errors=[]):Response
    {
        $pdo=($this->pdo)();$repo=new ApplicationRepository($pdo);$app=$repo->findApplication($id);if(!$app) {return $this->notFound($req);}
        $notifications=new ApplicationNotifications($pdo);
        $templates=['approved'=>$notifications->defaults('approved'),'rejected'=>$notifications->defaults('rejected')];
        $types=$repo->membershipTypes();
        $defaultType=(string)($types[array_search('active_member',array_column($types,'system_key'),true)]['id']??($types[0]['id']??''));
        $decision=$input['decision']??($req->getQueryParams()['decision']??'approved');
        if(!in_array($decision,['approved','rejected'],true)) {$decision='approved';}
        $values=$input??($templates[$decision]+['reason'=>'','decision'=>$decision]);
        $values+=['join_date'=>(new \DateTimeImmutable())->format('Y-m-d'),'membership_type_id'=>$defaultType,'reason'=>''];$safe=[];
        foreach($values as $key=>$value) {$safe[$key]=is_string($value)&&mb_check_encoding($value,'UTF-8')?$value:'';}
        $safe['decision']=in_array($safe['decision']??'',['approved','rejected'],true)?$safe['decision']:'approved';
        // Nach einem Fehler den Schritt oeffnen, der das fehlerhafte Feld enthaelt.
        $step=array_intersect_key($errors,array_flip(['subject','message']))?2:(array_intersect_key($errors,array_flip(['decision','join_date','membership_type_id','reason']))?1:0);
        return $req->getAttribute('view')->render($res,'members/decision.twig',['application'=>$app,'input'=>$safe,'errors'=>$errors,'start_step'=>$step,
            'templates'=>$templates,'membership_types'=>$types,
            'signatures'=>(new \Vereinskiste\Members\ApplicationSignatures($pdo))->forApplication($id),'mail_configured'=>$req->getAttribute('mail_configured',false)]);
    }
    public function signature(Request $req,Response $res,array $args):Response
    {
        $png=(new \Vereinskiste\Members\ApplicationSignatures(($this->pdo)()))->png((int)$args['id'],(int)$args['signature']);
        if($png===null) {return $this->notFound($req);}
        $res->getBody()->write($png);
        return $res->withHeader('Content-Type','image/png')->withHeader('Cache-Control','no-store');
    }
    public function pdf(Request $req,Response $res,array $args):Response
    {
        $pdo=($this->pdo)();$id=(int)$args['id'];
        $app=(new ApplicationRepository($pdo))->findApplication($id);
        if(!$app) {return $this->notFound($req);}
        return ApplicationPdfDownload::respond($pdo, $res, $app, $this->userId($req));
    }
}
