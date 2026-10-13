<?php
declare(strict_types=1);
namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Privacy\Settings;
use Vereinskiste\Privacy\Consents;
use Vereinskiste\Privacy\MemberPrivacy;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Ledger\ValidationError;

final class PrivacyController extends AdminController
{
    public function __construct(private Closure $pdo) {}
    public function settings(Request $req,Response $res):Response {return $this->settingsPage($req,$res);}
    public function saveSettings(Request $req,Response $res):Response
    {
        $input=$this->body($req);
        try {(new Settings(($this->pdo)()))->save($input,Fields::version($input['version']??null),$this->userId($req));}
        catch(ValidationError $e) {return $this->settingsPage($req,$res->withStatus(422),$input,$e->errors);}
        catch(Conflict) {return $this->settingsPage($req,$res->withStatus(409),$input,['version'=>'foundation.conflict']);}
        $this->flash($req,'foundation.saved');return $this->redirect('/settings/privacy');
    }
    public function application(Request $req,Response $res,array $args):Response
    {
        $id=(int)$args['id'];if(!(new \Vereinskiste\Members\ApplicationRepository(($this->pdo)()))->findApplication($id)) {return $this->notFound($req);}
        return $this->applicationPage($req,$res,$id);
    }
    public function anonymizeApplication(Request $req,Response $res,array $args):Response
    {
        $id=(int)$args['id'];if(!(new \Vereinskiste\Members\ApplicationRepository(($this->pdo)()))->findApplication($id)) {return $this->notFound($req);}
        $input=$this->body($req);
        try {
            if(($input['confirmed']??'')!=='1') {throw new ValidationError(['confirmed'=>'foundation.required']);}
            (new \Vereinskiste\Privacy\ApplicationPrivacy(($this->pdo)()))->anonymize($id,Fields::version($input['version']??null),$this->str($input,'fingerprint'),$this->str($input,'reason'),$this->userId($req));
        } catch(ValidationError $e) {return $this->applicationPage($req,$res->withStatus(422),$id,$e->errors);}
        catch(Conflict) {return $this->applicationPage($req,$res->withStatus(409),$id,['version'=>'foundation.conflict']);}
        $this->flash($req,'privacy.anonymized');return $this->redirect('/members/applications');
    }
    private function applicationPage(Request $req,Response $res,int $id,array $errors=[]):Response
    {
        return $req->getAttribute('view')->render($res,'privacy/application.twig',['preview'=>(new \Vereinskiste\Privacy\ApplicationPrivacy(($this->pdo)()))->preview($id),'errors'=>$errors]);
    }
    public function member(Request $req,Response $res,array $args):Response {return $this->memberPage($req,$res,(int)$args['id']);}
    public function consent(Request $req,Response $res,array $args):Response
    {
        $id=(int)$args['id'];if(!(new \Vereinskiste\Members\MemberRepository(($this->pdo)()))->find($id)) {return $this->notFound($req);}
        try {(new Consents(($this->pdo)()))->record($id,$this->body($req),$this->userId($req));}
        catch(ValidationError $e) {return $this->memberPage($req,$res->withStatus(422),$id,$e->errors);}
        $this->flash($req,'foundation.saved');return $this->redirect('/members/'.$id.'/privacy');
    }
    public function disclosure(Request $req,Response $res,array $args):Response
    {
        $id=(int)$args['id'];if(!(new \Vereinskiste\Members\MemberRepository(($this->pdo)()))->find($id)) {return $this->notFound($req);}
        $data=(new MemberPrivacy(($this->pdo)()))->disclosure($id);
        $res->getBody()->write(json_encode($data,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        return $res->withHeader('Content-Type','application/json; charset=utf-8')->withHeader('Content-Disposition','attachment; filename="mitgliedsauskunft-'.$id.'.json"')->withHeader('Cache-Control','no-store');
    }
    public function anonymize(Request $req,Response $res,array $args):Response
    {
        $id=(int)$args['id'];if(!(new \Vereinskiste\Members\MemberRepository(($this->pdo)()))->find($id)) {return $this->notFound($req);}
        $input=$this->body($req);
        try {
            if(($input['confirmed']??'')!=='1') {throw new ValidationError(['confirmed'=>'foundation.required']);}
            (new MemberPrivacy(($this->pdo)()))->anonymize($id,Fields::version($input['version']??null),$this->str($input,'fingerprint'),$this->str($input,'reason'),$this->userId($req));
        } catch(ValidationError $e) {return $this->memberPage($req,$res->withStatus(422),$id,$e->errors);}
        catch(Conflict) {return $this->memberPage($req,$res->withStatus(409),$id,['version'=>'foundation.conflict']);}
        $this->flash($req,'privacy.anonymized');return $this->redirect('/members/'.$id.'/privacy');
    }
    private function memberPage(Request $req,Response $res,int $id,array $errors=[]):Response
    {
        $member=(new \Vereinskiste\Members\MemberRepository(($this->pdo)()))->find($id);if(!$member) {return $this->notFound($req);}
        return $req->getAttribute('view')->render($res,'privacy/member.twig',[
            'preview'=>(new MemberPrivacy(($this->pdo)()))->preview($id),'member'=>$member,
            'consents'=>(new Consents(($this->pdo)()))->list($id),'kinds'=>Consents::KINDS,'errors'=>$errors,
        ]);
    }
    private function settingsPage(Request $req,Response $res,?array $input=null,array $errors=[]):Response
    {
        $state=(new Settings(($this->pdo)()))->get();$safe=[];
        foreach(($input??$state['data']) as $key=>$value) {$safe[$key]=is_string($value)&&mb_check_encoding($value,'UTF-8')?$value:'';}
        return $req->getAttribute('view')->render($res,'privacy/settings.twig',['input'=>$safe,'version'=>$input===null?$state['version']:$this->str($input,'version'),'fields'=>Settings::FIELDS,'errors'=>$errors]);
    }
}
