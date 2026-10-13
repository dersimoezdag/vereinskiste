<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Intake;

use PHPUnit\Framework\TestCase;
use Vereinskiste\Intake\DocumentProcessor;
use Vereinskiste\Tests\Invoices\InvoicesTest;
use Vereinskiste\Tests\Receipts\ReceiptFiles;

/** Real ClamAV/Bubblewrap/Tesseract integration on the provisioned CI runner. */
final class LocalProcessingTest extends TestCase
{
    use ReceiptFiles;
    protected function setUp():void
    {
        if(getenv('VK_INTAKE_PROCESSING_TEST')!=='1') {$this->markTestSkipped('Provisioned processing runner required');}
    }
    protected function tearDown():void {$this->cleanScratch();}
    public function test_real_scanner_accepts_xml_and_rejects_eicar():void
    {
        $processor=new DocumentProcessor();
        $result=$processor->process($this->tmpFile(InvoicesTest::xml()),'application/xml');
        $this->assertSame('clean',$result['security'],json_encode($result));
        $eicar='X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
        $this->assertSame('blocked',$processor->process($this->tmpFile($eicar),'application/xml')['security']);
    }
    public function test_real_image_ocr_runs_inside_sandbox():void
    {
        $image=imagecreatetruecolor(1000,200);imagefill($image,0,0,imagecolorallocate($image,255,255,255));
        imagestring($image,5,30,50,'Gesamtbetrag: 12,50 EUR',imagecolorallocate($image,0,0,0));
        $path=$this->scratchDir().'/ocr.png';imagepng($image,$path);imagedestroy($image);
        $result=(new DocumentProcessor())->process($path,'image/png');
        $this->assertSame('clean',$result['security'],json_encode($result));$this->assertArrayHasKey('extraction',$result);
        $this->assertNotSame('',trim($result['extraction']['text']));
    }
}
