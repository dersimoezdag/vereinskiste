<?php
declare(strict_types=1);
namespace Vereinskiste\ClubDocuments;
use Closure;
use Slim\App;
use Vereinskiste\Http\Middleware\Auth;
use Vereinskiste\Web\ClubDocumentController;
final class Routes
{
 public static function register(App $app,Closure $pdo): void
 {
  $controller=new ClubDocumentController($pdo);$base='/documents/{kind:minutes|constitution|rules}';
  $app->get('/documents',static fn($req,$res)=>$res->withStatus(302)->withHeader('Location','/documents/minutes'))->add(Auth::authenticated());
  foreach ([$base=>'index',$base.'/new'=>'create',$base.'/{id:[0-9]+}'=>'show',$base.'/{id:[0-9]+}/edit'=>'create',$base.'/{id:[0-9]+}/pdf'=>'pdf',$base.'/{id:[0-9]+}/versions/{version:[0-9]+}'=>'show'] as $path=>$action) {
   $app->get($path,[$controller,$action])->add(Auth::authenticated());
  }
  foreach ([$base.'/new',$base.'/{id:[0-9]+}'] as $path) { $app->post($path,[$controller,'save'])->add(Auth::requires('documents.write')); }
 }
}
