<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\ClubDocuments;

use PHPUnit\Framework\TestCase;
use Vereinskiste\ClubDocuments\Docx;
use Vereinskiste\Ledger\ValidationError;
use ZipArchive;

final class DocxTest extends TestCase
{
    private function read(string $xml): string
    {
        $path=tempnam(sys_get_temp_dir(),'docx-test-');
        try {
            $zip=new ZipArchive();$zip->open($path,ZipArchive::OVERWRITE);
            $zip->addFromString('[Content_Types].xml','<Types/>');
            $zip->addFromString('word/document.xml',$xml);$zip->close();
            return Docx::read($path);
        } finally { unlink($path); }
    }

    public function test_import_preserves_text_formatting_and_tables(): void
    {
        $html=$this->read('<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Beschluss &amp; Abstimmung</w:t></w:r></w:p><w:tbl><w:tr><w:tc><w:p><w:r><w:t>Angenommen</w:t></w:r></w:p></w:tc></w:tr></w:tbl></w:body></w:document>');
        self::assertStringContainsString('<strong>Beschluss &amp; Abstimmung</strong>',$html);
        self::assertStringContainsString('<td><p>Angenommen</p></td>',$html);
    }

    public function test_external_entities_are_rejected(): void
    {
        $this->expectException(ValidationError::class);
        $this->read('<!DOCTYPE document [<!ENTITY external SYSTEM "file:///etc/passwd">]><document>&external;</document>');
    }

    public function test_other_zip_documents_are_rejected(): void
    {
        $this->expectException(ValidationError::class);
        $this->read('<document/>');
    }
}
