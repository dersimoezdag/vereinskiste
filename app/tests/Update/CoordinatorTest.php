<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Update;

use PDO;
use Vereinskiste\Db\Migrator;
use Vereinskiste\Tests\TestCase;
use Vereinskiste\Tests\Receipts\ReceiptFiles;
use Vereinskiste\Update\Coordinator;
use Vereinskiste\Update\Files;
use Vereinskiste\Update\Runtime;
use Vereinskiste\Update\State;

final class CoordinatorTest extends TestCase
{
    use ReceiptFiles;
    use PackageFixture;
    private PDO $db;
    private Runtime $runtime;
    protected function setUp(): void
    {
        $this->db=$this->pdo(); (new Migrator($this->db,dirname(__DIR__,2) . '/migrations'))->migrate();
        $this->db->exec("INSERT INTO users(name,email,password_hash) VALUES ('Admin','admin@example.org','hash')");
        $this->runtime=new Runtime($this->scratchDir() . '/app'); Files::mkdir($this->runtime->instance);
    }
    protected function tearDown(): void { $this->cleanScratch(); }
    private function until(Coordinator $worker,string $phase): void
    {
        for ($i=0;$i<100;$i++) {
            $state=$worker->step();
            if ($state['phase']===$phase) { return; }
            if (in_array($state['phase'],['failed','rolled_back','recovery_required'],true)) { $this->fail(json_encode($state)); }
        }
        $this->fail('Zielphase nicht erreicht: ' . $phase);
    }
    public function test_success_resume_and_crash_after_commit_activate_exactly_once(): void
    {
        [$release,$http]=$this->package($this->runtime->instance,'CREATE TABLE auto_update_test(id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY)');
        $worker=new Coordinator($this->db,$this->runtime,$http); $worker->start($release,'1.0.0',1);
        $this->until($worker,'verify');
        $state=(new State($this->runtime))->load();
        $this->assertSame('1.1.0',$this->runtime->active()['version']);
        $this->assertSame($this->runtime->instance,$this->runtime->application());
        // Simulate process death after DB commit, before the phase write.
        $state['phase']='activate'; (new State($this->runtime))->save($state); $worker->step();
        $this->assertSame(1,(int)$this->db->query("SELECT count(*) FROM schema_migrations WHERE name='0011_update_test.sql'")->fetchColumn());
        $this->runtime->recordProbe($state['probe'],'1.1.0');
        $this->assertSame('done',$worker->step()['phase']); $this->assertNull($this->runtime->maintenance());
        $this->assertStringContainsString('/releases/',$this->runtime->application());
        $this->assertSame('done',$worker->step()['phase']);
        $this->assertSame(1,(int)$this->db->query("SELECT count(*) FROM audit_log WHERE action='auto_update_completed'")->fetchColumn());
        $this->assertStringStartsWith('/_release-assets/',$this->runtime->asset('app.css'));
    }
    public function test_failed_start_probe_restores_schema_data_and_previous_pointer(): void
    {
        [$release,$http]=$this->package($this->runtime->instance,'ALTER TABLE users ADD COLUMN target_only text');
        $worker=new Coordinator($this->db,$this->runtime,$http); $worker->start($release,'1.0.0',1); $this->until($worker,'verify');
        $state=(new State($this->runtime))->load(); $state['verify_until']=0; (new State($this->runtime))->save($state);
        $this->assertSame('rollback',$worker->step()['phase']);
        $this->assertSame('rolled_back',$worker->step()['phase']);
        $this->assertNull($this->runtime->active()); $this->assertNull($this->runtime->maintenance());
        $this->assertSame(0,(int)$this->db->query("SELECT count(*) FROM information_schema.columns WHERE table_name='users' AND column_name='target_only'")->fetchColumn());
        $this->assertSame('Admin',$this->db->query('SELECT name FROM users WHERE id=1')->fetchColumn());
    }
    public function test_migration_error_rolls_back_and_unfreezes_the_original_version(): void
    {
        [$release,$http]=$this->package($this->runtime->instance,'CREATE TABLE transient(id integer PRIMARY KEY); SELECT 1/0;');
        $worker=new Coordinator($this->db,$this->runtime,$http); $worker->start($release,'1.0.0',1); $this->until($worker,'activate');
        $this->assertSame('rollback',$worker->step()['phase']); $this->assertSame('rolled_back',$worker->step()['phase']);
        $this->assertNull($this->db->query("SELECT to_regclass('public.transient')")->fetchColumn());
        $this->assertNull($this->runtime->active()); $this->assertNull($this->runtime->maintenance());
    }
    public function test_snapshot_waits_for_in_flight_mutations_on_another_connection(): void
    {
        [$release,$http]=$this->package($this->runtime->instance); $worker=new Coordinator($this->db,$this->runtime,$http);
        $worker->start($release,'1.0.0',1); $this->until($worker,'waiting');
        $other=$this->connect(); $other->query('SELECT pg_advisory_lock_shared(' . Coordinator::BARRIER . ')');
        try { $this->assertSame('waiting',$worker->step()['phase']); $this->assertNotNull($this->runtime->maintenance()); }
        finally { $other->query('SELECT pg_advisory_unlock_shared(' . Coordinator::BARRIER . ')'); }
        $this->assertSame('backup',$worker->step()['phase']);
    }
}
