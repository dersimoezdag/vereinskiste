<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Update;

use Vereinskiste\Db\Migrator;
use Vereinskiste\Tests\TestCase;
use Vereinskiste\Tests\Receipts\ReceiptFiles;
use Vereinskiste\Update\Coordinator;
use Vereinskiste\Update\Files;
use Vereinskiste\Update\Runtime;
use Vereinskiste\Update\State;

/** Actual front-controller, Composer and Twig boot in a separate PHP process. */
final class NativeBootTest extends TestCase
{
    use ReceiptFiles;
    use PackageFixture;
    protected function tearDown(): void { $this->cleanScratch(); }
    public function test_a_complete_package_can_boot_from_its_private_slot_and_record_readiness(): void
    {
        $artifact=getenv('VK_UPDATE_PACKAGE');
        if (!$artifact || !is_file($artifact)) { $this->markTestSkipped('Build a release fixture and set VK_UPDATE_PACKAGE.'); }
        $db=$this->pdo(); (new Migrator($db,dirname(__DIR__,2) . '/migrations'))->migrate();
        $db->exec("INSERT INTO users(name,email,password_hash) VALUES ('Admin','admin@example.org','hash')");
        $root=$this->scratchDir() . '/instance'; $app=$root . '/app'; Files::mkdir($app . '/config'); Files::mkdir($app . '/storage'); Files::mkdir($root . '/public');
        $fixture=$this->scratchDir() . '/full.zip'; copy($artifact,$fixture);
        $zip=new \ZipArchive(); $zip->open($fixture); $zip->addFromString('app/VERSION','1.1.0'); $zip->close();
        [$release,$http]=$this->signed($app,file_get_contents($fixture),'1.1.0');
        $runtime=new Runtime($app); $worker=new Coordinator($db,$runtime,$http); $worker->start($release,'1.0.0',1);
        for ($i=0;$i<200;$i++) {
            $status=$worker->step(); if ($status['phase']==='verify') { break; }
            if (in_array($status['phase'],['failed','recovery_required','rolled_back'],true)) { $this->fail(json_encode($status)); }
        }
        $this->assertSame('verify',$status['phase']);
        $config=['require_https'=>false,'db'=>['dsn'=>getenv('VK_TEST_DSN'),'user'=>getenv('VK_TEST_USER')?:'vk','pass'=>getenv('VK_TEST_PASS')?:'vk']];
        file_put_contents($app . '/config/config.php','<?php return ' . var_export($config,true) . ';');
        copy(dirname(__DIR__,2) . '/update-bootstrap.php',$app . '/update-bootstrap.php');
        Files::mkdir($app . '/src/Update');
        foreach (['Runtime','Files','Failure'] as $class) { copy(dirname(__DIR__,2) . '/src/Update/' . $class . '.php',$app . '/src/Update/' . $class . '.php'); }
        copy(dirname(__DIR__,3) . '/public/index.php',$root . '/public/index.php');
        $socket=stream_socket_server('tcp://127.0.0.1:0'); $address=stream_socket_get_name($socket,false); fclose($socket);
        $port=(int)substr(strrchr($address,':'),1);
        $process=proc_open([PHP_BINARY,'-S','127.0.0.1:' . $port,'-t',$root . '/public'],
            [0=>['pipe','r'],1=>['file',$this->scratchDir() . '/server.log','a'],2=>['file',$this->scratchDir() . '/server.log','a']],$pipes);
        $this->assertIsResource($process); fclose($pipes[0]);
        try {
            $probe=(new State($runtime))->publicStatus()['probe_url']; $body=false;
            for ($attempt=0;$attempt<30;$attempt++) {
                usleep(100000);
                $body=@file_get_contents('http://127.0.0.1:' . $port . $probe,false,stream_context_create(['http'=>['timeout'=>3,'ignore_errors'=>true]]));
                if ($body!==false) { break; }
            }
            $this->assertIsString($body,(string)file_get_contents($this->scratchDir() . '/server.log'));
            $this->assertSame(['ready'=>true,'version'=>'1.1.0'],json_decode($body,true),$body);
            $this->assertSame('done',$worker->step()['phase']);
            $this->assertNull($runtime->maintenance());
            $asset=@file_get_contents('http://127.0.0.1:' . $port . $runtime->asset('app.css'));
            $this->assertStringContainsString('.material-symbol',$asset);
        } finally { proc_terminate($process); proc_close($process); }
    }
}
