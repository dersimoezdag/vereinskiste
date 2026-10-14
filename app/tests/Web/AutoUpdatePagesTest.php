<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Web;

use PDO;
use Slim\Psr7\Factory\ServerRequestFactory;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;
use Vereinskiste\Tests\TestCase;
use Vereinskiste\Tests\Receipts\ReceiptFiles;
use Vereinskiste\Update\Files;

final class AutoUpdatePagesTest extends TestCase
{
    use WebHarness;
    use ReceiptFiles;
    private PDO $pdo;
    protected function setUp(): void
    {
        $this->pdo=$this->pdo(); (new Migrator($this->pdo,dirname(__DIR__,2) . '/migrations'))->migrate();
        $this->store=new ArraySessionStore();
        $this->app=Kernel::create(['pdo'=>$this->pdo,'session_store'=>$this->store,'app_dir'=>$this->scratchDir(),
            'migration_dir'=>dirname(__DIR__,2) . '/migrations','security_dir'=>$this->securityDir(),
            'require_https'=>false,'maintenance'=>true]);
    }
    protected function tearDown(): void { $this->cleanScratch(); }
    public function test_progress_status_and_installation_routes_require_settings_permission_and_csrf(): void
    {
        $this->loginAs(['kassenwart']);
        foreach (['/settings/auto-update','/settings/auto-update/status'] as $path) { $this->assertSame(403,$this->get($path)->getStatusCode()); }
        foreach (['start','step','cancel'] as $action) { $this->assertSame(403,$this->postForm('/settings/auto-update/' . $action,[])->getStatusCode()); }
        $this->pdo->exec("UPDATE user_roles SET role='admin'");
        $this->assertSame(200,$this->get('/settings/auto-update')->getStatusCode());
        $this->assertSame(['phase'=>'idle'],json_decode((string)$this->get('/settings/auto-update/status')->getBody(),true));
        $request=(new ServerRequestFactory())->createServerRequest('POST','/settings/auto-update/step')->withParsedBody([]);
        $this->assertSame(403,$this->app->handle($request)->getStatusCode());
        $this->assertSame(422,$this->postForm('/settings/auto-update/start',[])->getStatusCode());
    }
    public function test_maintenance_blocks_mutations_and_normal_pages_but_keeps_recovery_available(): void
    {
        $this->loginAs(['vorstand']); Files::mkdir($this->scratchDir() . '/storage/updates');
        Files::json($this->scratchDir() . '/storage/updates/maintenance.json',[
            'id'=>str_repeat('a',32),'previous'=>null,'probe'=>str_repeat('b',64)]);
        $this->assertSame(302,$this->get('/')->getStatusCode());
        $this->assertSame('/settings/auto-update',$this->get('/')->getHeaderLine('Location'));
        $this->assertSame(503,$this->get('/members')->getStatusCode());
        $blocked=(new ServerRequestFactory())->createServerRequest('POST','/accounts')->withParsedBody([
            '_csrf'=>\Vereinskiste\Http\Middleware\Csrf::token($this->store),'name'=>'Nicht anlegen']);
        $this->assertSame(503,$this->app->handle($blocked)->getStatusCode());
        $this->assertSame(200,$this->get('/settings/auto-update')->getStatusCode());
        $this->assertSame(503,$this->get('/health')->getStatusCode());
        $this->assertSame(503,$this->get('/_update-probe/' . str_repeat('c',64))->getStatusCode());
        $this->assertSame(0,(int)$this->pdo->query('SELECT count(*) FROM accounts')->fetchColumn());
    }
    public function test_visitors_and_non_admins_see_static_maintenance_even_on_update_routes(): void
    {
        $this->loginAs(['kassenwart']);
        $this->enableMaintenance();
        foreach (['/', '/members', '/settings/auto-update', '/settings/auto-update/status'] as $path) {
            $response=$this->get($path);
            $this->assertSame(503,$response->getStatusCode());
            $this->assertSame('no-store',$response->getHeaderLine('Cache-Control'));
            $this->assertSame('10',$response->getHeaderLine('Retry-After'));
            $html=(string)$response->getBody();
            $this->assertStringContainsString('Wir aktualisieren die Vereinskiste',$html);
            $this->assertStringNotContainsString('<script',$html);
            $this->assertStringNotContainsString('sidebar-menu',$html);
            $this->assertStringNotContainsString('automatic-update',$html);
        }
        $this->store->destroy();
        $this->assertSame(503,$this->get('/')->getStatusCode());
        $this->assertSame(200,$this->get('/login')->getStatusCode());
    }

    public function test_admin_controls_remain_available_but_other_admin_functions_are_blocked(): void
    {
        $user=$this->loginAs(['admin']);
        $this->enableMaintenance();
        $this->assertSame(200,$this->get('/settings/auto-update')->getStatusCode());
        $this->assertSame(200,$this->get('/settings/auto-update/status')->getStatusCode());
        foreach (['/users','/settings','/settings/backup','/settings/auto-update-anything'] as $path) {
            $this->assertSame(503,$this->get($path)->getStatusCode());
        }
        $request=(new ServerRequestFactory())->createServerRequest('POST','/settings/auto-update/cancel');
        $this->assertSame(403,$this->app->handle($request)->getStatusCode());
        $request=$request->withParsedBody(['_csrf'=>\Vereinskiste\Http\Middleware\Csrf::token($this->store)]);
        $this->assertSame(302,$this->app->handle($request)->getStatusCode());
        $this->pdo->prepare('DELETE FROM user_roles WHERE user_id=?')->execute([$user]);
        $this->assertSame(503,$this->get('/settings/auto-update/status')->getStatusCode());
    }

    public function test_exclusive_update_lock_still_blocks_admin_business_writes(): void
    {
        $this->loginAs(['admin']);
        $other=$this->connect();
        $key=\Vereinskiste\Update\Coordinator::BARRIER;
        $other->query('SELECT pg_advisory_lock(' . $key . ')');
        try {
            $request=(new ServerRequestFactory())->createServerRequest('POST','/accounts')->withParsedBody([
                '_csrf'=>\Vereinskiste\Http\Middleware\Csrf::token($this->store),'name'=>'Nicht anlegen']);
            $this->assertSame(503,$this->app->handle($request)->getStatusCode());
            $this->assertSame(0,(int)$this->pdo->query('SELECT count(*) FROM accounts')->fetchColumn());
        } finally { $other->query('SELECT pg_advisory_unlock(' . $key . ')'); }
    }

    private function enableMaintenance(): void
    {
        Files::mkdir($this->scratchDir() . '/storage/updates');
        Files::json($this->scratchDir() . '/storage/updates/maintenance.json',[
            'id'=>str_repeat('a',32),'previous'=>null,'probe'=>str_repeat('b',64)]);
    }

}
