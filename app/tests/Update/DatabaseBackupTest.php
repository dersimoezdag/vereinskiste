<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Update;

use Vereinskiste\Db\Migrator;
use Vereinskiste\Ledger\BookingService;
use Vereinskiste\Tests\TestCase;
use Vereinskiste\Tests\Receipts\ReceiptFiles;
use Vereinskiste\Update\DatabaseBackup;
use Vereinskiste\Update\DatabaseRestore;
use Vereinskiste\Update\DatabaseSchema;
use Vereinskiste\Update\Failure;

final class DatabaseBackupTest extends TestCase
{
    use ReceiptFiles;
    protected function tearDown(): void { $this->cleanScratch(); }
    public function test_restore_preserves_ledger_history_constraints_triggers_and_identity_sequences(): void
    {
        $db=$this->pdo(); (new Migrator($db,dirname(__DIR__,2) . '/migrations'))->migrate();
        $db->exec("INSERT INTO users(name,email,password_hash) VALUES ('Admin','admin@example.org','hash')");
        $db->exec("INSERT INTO accounts(name,type,opening_date) VALUES ('Kasse','cash','2026-01-01')");
        $service=new BookingService($db);
        $booking=$service->create(['account_id'=>'1','booking_date'=>'2026-10-01','amount'=>'12,50','direction'=>'in','description'=>'Original'],1);
        $service->update($booking['id'],['description'=>'Vorher'],'Korrektur',1,1);
        $schema=(new DatabaseSchema($db))->capture(); $before=[];
        foreach ($schema['tables'] as $table) { $before[$table['name']]=$db->query('SELECT * FROM ' . DatabaseSchema::quote($table['name']) . ' ORDER BY 1')->fetchAll(); }
        $backup=new DatabaseBackup($db,$this->scratchDir() . '/backup'); $progress=$backup->start();
        do { $progress=$backup->step($progress); } while (!($progress['done']??false));
        $db->exec("ALTER TABLE users ADD COLUMN extra text; CREATE TABLE newer(id bigint PRIMARY KEY)");
        $service->update($booking['id'],['description'=>'Nachher'],'Neue Version',2,1);
        (new DatabaseRestore($db,$this->scratchDir() . '/backup'))->run();
        foreach ($before as $table=>$rows) { $this->assertSame($rows,$db->query('SELECT * FROM ' . DatabaseSchema::quote($table) . ' ORDER BY 1')->fetchAll(),$table); }
        $this->assertNull($db->query("SELECT to_regclass('public.newer')")->fetchColumn());
        $user=$db->query("INSERT INTO users(name,email,password_hash) VALUES ('Nächster','next@example.org','hash') RETURNING id")->fetchColumn();
        $this->assertSame(2,(int)$user);
        try { $db->exec('DELETE FROM bookings'); $this->fail('Sperrtrigger'); } catch (\PDOException) { $this->assertTrue(true); }
    }
    public function test_tampered_backup_is_rejected_before_any_schema_change(): void
    {
        $db=$this->pdo(); (new Migrator($db,dirname(__DIR__,2) . '/migrations'))->migrate();
        $backup=new DatabaseBackup($db,$this->scratchDir() . '/backup'); $progress=$backup->start();
        do { $progress=$backup->step($progress); } while (!($progress['done']??false));
        file_put_contents($this->scratchDir() . '/backup/0.jsonl','tampered');
        try { (new DatabaseRestore($db,$this->scratchDir() . '/backup'))->run(); $this->fail('Integrität'); }
        catch (Failure $e) { $this->assertSame('backup',$e->reason); }
        $this->assertSame('users',$db->query("SELECT to_regclass('public.users')::text")->fetchColumn());
    }
}
