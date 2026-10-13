<?php
declare(strict_types=1);
namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Vereinskiste\Install\GitHubReleases;
use Vereinskiste\Install\Updater;
use Vereinskiste\Update\Coordinator;
use Vereinskiste\Update\Failure;
use Vereinskiste\Update\Runtime;
use Vereinskiste\Update\State;

final class AutoUpdateController extends AdminController
{
    public function __construct(private Closure $pdo,private Runtime $runtime,private string $version,
        private string $migrations,private GitHubReleases $releases) {}
    public function show(Request $req,Response $res,?string $error=null): Response
    {
        return $req->getAttribute('view')->render($res->withHeader('Cache-Control','no-store'),'install/auto_update.twig',[
            'operation'=>(new State($this->runtime))->publicStatus(),'error'=>$error]);
    }
    public function start(Request $req,Response $res): Response
    {
        $body=$this->body($req);
        if (($body['backup_confirmed']??'')!=='1') { return $this->show($req,$res->withStatus(422),'update.backup_required'); }
        $actor=$this->userId($req); $req->getAttribute('session')->commit();
        try {
            if ((new Updater(($this->pdo)(),$this->migrations))->needsUpdate()) { throw new Failure('schema_pending'); }
            $release=$this->releases->check($this->version);
            if (($body['version']??'')!==($release['version']??null)) { throw new Failure('release_changed'); }
            (new Coordinator(($this->pdo)(),$this->runtime))->start($release,$this->version,$actor);
        } catch (Failure $e) { return $this->show($req,$res->withStatus(422),'auto_update.error.' . $e->reason); }
        return $this->redirect('/settings/auto-update');
    }
    public function status(Request $req,Response $res): Response { return $this->json($res,(new State($this->runtime))->publicStatus()); }
    public function step(Request $req,Response $res): Response
    {
        $req->getAttribute('session')->commit();
        try { $result=(new Coordinator(($this->pdo)(),$this->runtime))->step(); }
        catch (Failure $e) { return $this->json($res->withStatus($e->reason==='busy'?409:422),['error'=>$e->reason]); }
        return str_contains($req->getHeaderLine('Accept'),'application/json') ? $this->json($res,$result) : $this->redirect('/settings/auto-update');
    }
    public function cancel(Request $req,Response $res): Response
    {
        $req->getAttribute('session')->commit();
        try { (new Coordinator(($this->pdo)(),$this->runtime))->cancel(); }
        catch (Failure $e) { return $this->show($req,$res->withStatus(409),'auto_update.error.' . $e->reason); }
        return $this->redirect('/settings/auto-update');
    }
    public function probe(Request $req,Response $res,array $args): Response
    {
        if (!$this->runtime->probeAllowed($args['token'])) { return $res->withStatus(404); }
        $db=($this->pdo)(); $db->query('SELECT 1');
        if ((new Updater($db,$this->migrations))->needsUpdate()) { return $res->withStatus(503); }
        // Render the real target layout to exercise Twig, auth lookup and the new dependency set.
        $req->getAttribute('view')->fetch('layout.twig',[]);
        $this->runtime->recordProbe($args['token'],$this->version);
        return $this->json($res,['ready'=>true,'version'=>$this->version]);
    }
    private function json(Response $res,array $data): Response
    {
        $res->getBody()->write(json_encode($data,JSON_THROW_ON_ERROR));
        return $res->withHeader('Content-Type','application/json')->withHeader('Cache-Control','no-store');
    }
}
