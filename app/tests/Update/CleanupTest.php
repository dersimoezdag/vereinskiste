<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Update;

use PHPUnit\Framework\TestCase;
use Vereinskiste\Tests\Receipts\ReceiptFiles;
use Vereinskiste\Update\Cleanup;
use Vereinskiste\Update\Files;
use Vereinskiste\Update\Runtime;

final class CleanupTest extends TestCase
{
    use ReceiptFiles;
    private Runtime $runtime;
    private const ACTIVE='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const PREVIOUS='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const OLDEST='cccccccccccccccccccccccccccccccc';
    private const FAILED='dddddddddddddddddddddddddddddddd';
    protected function setUp(): void
    {
        $this->runtime=new Runtime($this->scratchDir() . '/app'); Files::mkdir($this->runtime->directory());
    }
    protected function tearDown(): void { $this->cleanScratch(); }
    private function release(string $id): void
    {
        $dir=$this->runtime->directory() . '/releases/' . $id . '/app'; Files::mkdir($dir);
        file_put_contents($dir . '/index.php','<?php'); Files::json(dirname($dir) . '/verified.json',['version'=>'x']);
    }
    private function operation(string $id,int $changed): string
    {
        $dir=$this->runtime->directory() . '/operations/' . $id; Files::mkdir($dir . '/backup');
        file_put_contents($dir . '/package.zip','zip'); file_put_contents($dir . '/backup/0.jsonl','{"name":"Mitglied"}');
        Files::json($dir . '/state.json',['id'=>$id]); touch($dir . '/state.json',$changed);
        return $dir;
    }
    private function current(string $phase,?string $previous=self::PREVIOUS): void
    {
        Files::json($this->runtime->directory() . '/operation.json',['id'=>self::ACTIVE,'phase'=>$phase,'previous'=>$previous===null?null:['id'=>$previous,'version'=>'1.0.0']]);
    }
    public function test_keeps_active_and_previous_release_and_expires_old_exports(): void
    {
        $now=time(); $old=$now-Cleanup::RETENTION-60;
        foreach ([self::ACTIVE,self::PREVIOUS,self::OLDEST,self::FAILED] as $id) { $this->release($id); }
        $this->runtime->pointer(['id'=>self::ACTIVE,'version'=>'1.1.0']); $this->current('done');
        $current=$this->operation(self::ACTIVE,$now); $recent=$this->operation(self::FAILED,$now-3600); $expired=$this->operation(self::OLDEST,$old);
        (new Cleanup($this->runtime,$now))->run();
        $releases=$this->runtime->directory() . '/releases/';
        $this->assertDirectoryExists($releases . self::ACTIVE); $this->assertDirectoryExists($releases . self::PREVIOUS);
        $this->assertDirectoryDoesNotExist($releases . self::OLDEST); $this->assertDirectoryDoesNotExist($releases . self::FAILED);
        // Downloads go at once; database exports stay for the retention period.
        $this->assertFileDoesNotExist($current . '/package.zip'); $this->assertDirectoryExists($current . '/backup');
        $this->assertFileDoesNotExist($recent . '/package.zip'); $this->assertDirectoryExists($recent . '/backup');
        $this->assertDirectoryDoesNotExist($expired);
        // After the retention period only the record of the last operation remains.
        (new Cleanup($this->runtime,$now+Cleanup::RETENTION+60))->run();
        $this->assertDirectoryDoesNotExist($current . '/backup'); $this->assertFileExists($current . '/state.json');
        $this->assertDirectoryDoesNotExist($recent);
        $this->assertSame(self::ACTIVE,$this->runtime->active()['id']);
    }
    public function test_does_nothing_while_an_update_is_running_or_the_application_is_frozen(): void
    {
        $old=time()-Cleanup::RETENTION-60; $this->release(self::OLDEST); $dir=$this->operation(self::OLDEST,$old);
        foreach (['backup','rollback','recovery_required'] as $phase) {
            $this->current($phase,null);
            $this->assertSame(0,(new Cleanup($this->runtime))->run(),$phase);
        }
        $this->current('done',null); Files::json($this->runtime->directory() . '/maintenance.json',['id'=>self::ACTIVE]);
        $this->assertSame(0,(new Cleanup($this->runtime))->run());
        $this->assertDirectoryExists($dir); $this->assertDirectoryExists($this->runtime->directory() . '/releases/' . self::OLDEST);
    }
    public function test_skips_quietly_while_the_update_worker_holds_the_lock(): void
    {
        $this->release(self::OLDEST); $this->current('done',null);
        $lock=fopen($this->runtime->directory() . '/worker.lock','c'); flock($lock,LOCK_EX);
        try { $this->assertSame(0,(new Cleanup($this->runtime))->run()); }
        finally { flock($lock,LOCK_UN); fclose($lock); }
        $this->assertDirectoryExists($this->runtime->directory() . '/releases/' . self::OLDEST);
    }
    public function test_removes_symlinks_without_following_them(): void
    {
        if (!function_exists('symlink') || PHP_OS_FAMILY==='Windows') { $this->markTestSkipped('symlink'); }
        $outside=$this->scratchDir() . '/outside'; mkdir($outside); file_put_contents($outside . '/keep.txt','x');
        $this->release(self::OLDEST); symlink($outside,$this->runtime->directory() . '/releases/' . self::OLDEST . '/app/link');
        $this->current('done',null); (new Cleanup($this->runtime))->run();
        $this->assertFileExists($outside . '/keep.txt');
        $this->assertDirectoryDoesNotExist($this->runtime->directory() . '/releases/' . self::OLDEST);
    }
}
