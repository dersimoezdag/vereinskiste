<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Update;

use Vereinskiste\Tests\TestCase;
use Vereinskiste\Tests\Receipts\ReceiptFiles;
use Vereinskiste\Update\Archive;
use Vereinskiste\Update\Failure;

final class ArchiveTest extends TestCase
{
    use ReceiptFiles;
    protected function tearDown(): void { $this->cleanScratch(); }
    public function test_traversal_symlinks_and_config_overwrites_are_rejected(): void
    {
        foreach (['app/../config.php','app/config/config.php','public/assets/link.svg'] as $name) {
            $path=$this->scratchDir() . '/bad.zip'; $zip=new \ZipArchive(); $zip->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE);
            $zip->addFromString($name,'payload');
            if (str_ends_with($name,'link.svg')) { $zip->setExternalAttributesName($name,\ZipArchive::OPSYS_UNIX,0120777<<16); }
            $zip->close();
            try { (new Archive())->inspect($path,'1.0.0'); $this->fail('Unsicheres Archiv'); }
            catch (Failure $e) { $this->assertSame('archive',$e->reason); }
        }
    }
}
