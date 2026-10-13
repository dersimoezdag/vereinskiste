<?php
declare(strict_types=1);
namespace Vereinskiste\Intake;

use Vereinskiste\Ledger\ValidationError;

/** Structural checks for shared hosting; explicitly NOT an antivirus scan. */
final class DocumentInspection
{
    public function inspect(string $path,string $mime): void
    {
        if($mime!=='application/pdf') {return;} // MIME/image/XML limits already enforced by FileStorage.
        $bytes=file_get_contents($path);
        if($bytes===false||strlen($bytes)>10485760||!str_starts_with($bytes,'%PDF-')||!str_contains(substr($bytes,-2048),'%%EOF')) {
            throw new ValidationError(['receipt'=>'intake.document.invalid_pdf']);
        }
        // Decode escaped PDF name characters before checking active features.
        $names=preg_replace_callback('/#([a-fA-F0-9]{2})/',static fn(array $m):string=>chr(hexdec($m[1])),$bytes);
        if(preg_match('~/(?:JavaScript|JS|OpenAction|AA|Launch|EmbeddedFile|RichMedia|XFA|AcroForm|SubmitForm|GoToR|URI|Encrypt|ObjStm)\b~i',$names)) {
            throw new ValidationError(['receipt'=>'intake.document.active_pdf']);
        }
    }
}
