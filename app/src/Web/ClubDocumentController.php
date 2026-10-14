<?php
declare(strict_types=1);
namespace Vereinskiste\Web;
use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Club\Profile;
use Vereinskiste\ClubDocuments\Access;
use Vereinskiste\ClubDocuments\Content;
use Vereinskiste\ClubDocuments\Input;
use Vereinskiste\ClubDocuments\Pdf;
use Vereinskiste\ClubDocuments\PdfStorage;
use Vereinskiste\ClubDocuments\Repository;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Receipts\UploadInput;
final class ClubDocumentController extends AdminController
{
 public function __construct(private Closure $pdo) {}
 private function writer(Request $req): bool { return $req->getAttribute('auth')->can('documents.write'); }
 public function index(Request $req,Response $res,array $args): Response
 {
  $repo=new Repository(($this->pdo)());$kind=$args['kind'];$writer=$this->writer($req);$page=max(1,min(10000,(int)($req->getQueryParams()['page'] ?? 1)));
  $root=$kind==='minutes'?null:$repo->root($kind);
  $rows=$kind==='minutes'?$repo->listing($writer,$page):($root?$repo->history($root,$writer,$page):[]);
  $latest=$root && $writer ? $repo->version($root,null,true):null;
  return $req->getAttribute('view')->render($res,'documents/index.twig',[
   'kind'=>$kind,'root'=>$root,'document'=>$root?$repo->version($root):null,
   'draft'=>$latest && $latest['status']==='draft'?$latest:null,
   'rows'=>array_slice($rows,0,50),'more'=>count($rows)>50,'page'=>$page,
  ]);
 }
 public function create(Request $req,Response $res,array $args): Response
 {
  if (!$this->writer($req)) { return $this->notFound($req); }
  $repo=new Repository(($this->pdo)());$kind=$args['kind'];$id=isset($args['id'])?(int)$args['id']:null;
  $root=$kind==='minutes' && $id===null?null:$repo->root($kind,$id);
  if ($id!==null && !$root) { return $this->notFound($req); }
  $latest=$root?$repo->version($root,null,true):null;
  $input=$latest ?? ['title'=>'','label'=>'','document_date'=>date('Y-m-d'),'meeting_type'=>'board','source'=>'editor'];
  $input['generation']=$root['generation'] ?? 0;$input['status']='draft';
  if ($latest && $latest['status']==='published' && $kind!=='minutes') { $input['label']=''; }
  return $this->form($req,$res,$kind,$root,$input);
 }
 public function save(Request $req,Response $res,array $args): Response
 {
  $repo=new Repository(($this->pdo)());$kind=$args['kind'];$id=isset($args['id'])?(int)$args['id']:null;
  $root=$kind==='minutes' && $id===null?null:$repo->root($kind,$id);
  if ($id!==null && !$root) { return $this->notFound($req); }
  $body=$this->body($req);
  try {
   $upload=UploadInput::fromRequest($req);
   if (($body['source'] ?? '')==='docx') {
    if (!$upload) { throw new ValidationError(['receipt'=>'validation.receipt.none']); }
    $body['content']=\Vereinskiste\ClubDocuments\Docx::read($upload['path']);
    $body['source']='editor';$upload=null;
   }
   $saved=$repo->save($kind,$id,Input::read($body,$kind),$upload,Fields::version($body['generation'] ?? null),$this->userId($req));
  } catch (ValidationError|Conflict $e) {
   return $this->form($req,$res->withStatus($e instanceof Conflict?409:422),$kind,$root,$body,$e instanceof Conflict?['generation'=>'foundation.conflict']:$e->errors);
  }
  $this->flash($req,'foundation.saved');return $this->redirect('/documents/'.$kind.($kind==='minutes'?'/'.$saved:''));
 }
 private function selected(Request $req,array $args): ?array
 {
  $repo=new Repository(($this->pdo)());$root=$repo->root($args['kind'],(int)$args['id']);
  if (!$root) { return null; }
  $version=isset($args['version'])?(int)$args['version']:null;
  $document=$repo->version($root,$version,$args['kind']==='minutes' && $this->writer($req));
  if (!$document && $version===null && $this->writer($req)) { $document=$repo->version($root,null,true); }
  return $document && Access::canRead($document,$req->getAttribute('auth'),$version!==null)?$document:null;
 }
 public function show(Request $req,Response $res,array $args): Response
 {
  $document=$this->selected($req,$args);if (!$document) { return $this->notFound($req); }
  return $req->getAttribute('view')->render($res,'documents/show.twig',['kind'=>$args['kind'],'document'=>$document,'historic'=>isset($args['version'])]);
 }
 public function pdf(Request $req,Response $res,array $args): Response
 {
  $query=$req->getQueryParams();if (isset($query['version'])) { $args['version']=(int)$query['version']; }
  $document=$this->selected($req,$args);if (!$document) { return $this->notFound($req); }
  if ($document['source']==='pdf') {
   $file=(new PdfStorage(($this->pdo)()))->open((int)$document['file_id']);if (!$file) { return $this->notFound($req); }
   $bytes=$file['bytes'];$name=$file['name'];
  } else { $bytes=Pdf::render($document,(new Profile(($this->pdo)()))->name());$name='Dokument-'.$document['document_id'].'-V'.$document['version'].'.pdf'; }
  $res->getBody()->write($bytes);
  return $res->withHeader('Content-Type','application/pdf')->withHeader('Content-Disposition',ReceiptController::contentDisposition($name))->withHeader('Content-Security-Policy',"default-src 'none'; sandbox");
 }
 private function form(Request $req,Response $res,string $kind,?array $root,array $input,array $errors=[]): Response
 {
  $safe=[];foreach($input as $key=>$value) { $safe[$key]=is_scalar($value) && mb_check_encoding((string)$value,'UTF-8')?(string)$value:''; }
  if(isset($safe['content'])) { $safe['content']=Content::clean(mb_substr($safe['content'],0,200000)); }
  return $req->getAttribute('view')->render($res,'documents/form.twig',['kind'=>$kind,'root'=>$root,'input'=>$safe,'errors'=>$errors]);
 }
}
