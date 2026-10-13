<?php
declare(strict_types=1);
namespace Vereinskiste\Intake;

/** Antivirus must succeed before any document parser runs. */
final class DocumentProcessor
{
    public function __construct(private Sandbox $sandbox = new Sandbox()) {}

    public function process(string $path, string $mime): array
    {
        $dir = sys_get_temp_dir() . '/vk-document-' . bin2hex(random_bytes(16));
        if (!mkdir($dir, 0700)) { throw new \RuntimeException('Processing unavailable'); }
        try {
            $ext = match ($mime) {'application/pdf' => 'pdf','image/jpeg' => 'jpg','image/png' => 'png','application/xml' => 'xml',default => throw new \RuntimeException('Unsupported document')};
            if (!copy($path, $dir . '/input.' . $ext)) { throw new \RuntimeException('Processing unavailable'); }
            $input = '/work/input.' . $ext;
            $scan = $this->sandbox->run(['/usr/bin/clamscan','--database=/signatures','--no-summary','--alert-encrypted=yes','--alert-exceeds-max=yes','--max-scansize=32M','--max-filesize=10M','--max-files=100','--max-recursion=10','--fail-if-cvd-older-than=7',$input], $dir);
            if ($scan['code'] !== 0) { return ['security' => $scan['code'] === 1 ? 'blocked' : 'error', 'diagnostic' => ['code'=>$scan['code'],'error'=>substr($scan['error'],0,1000)]]; }
            if ($mime === 'application/xml') { return ['security' => 'clean']; }
            $images = [$input];
            if ($mime === 'application/pdf') {
                $info = $this->sandbox->run(['/usr/bin/pdfinfo',$input], $dir, 5);
                if ($info['code'] !== 0 || !preg_match('/^Pages:\s+(\d+)/m', $info['output'], $m) || (int) $m[1] > 5) {
                    return ['security' => 'clean','manual' => true];
                }
                $raster = $this->sandbox->run(['/usr/bin/pdftoppm','-f','1','-l','5','-scale-to','2200','-png',$input,'/work/page'], $dir, 15);
                if ($raster['code'] !== 0) { return ['security' => 'clean','manual' => true]; }
                $images = array_map(static fn(string $p): string => '/work/' . basename($p), glob($dir . '/page-*.png') ?: []);
            }
            $text = '';
            foreach ($images as $image) {
                $ocr = $this->sandbox->run(['/usr/bin/tesseract',$image,'stdout','-l','deu+eng','--psm','3'], $dir, 10);
                if ($ocr['code'] !== 0) { return ['security' => 'clean','manual' => true]; }
                $text .= $ocr['output'] . "\n";
            }
            return ['security' => 'clean', 'extraction' => OcrSuggestions::extract($text)];
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) { if (is_file($file) && !is_link($file)) { unlink($file); } }
            @rmdir($dir);
        }
    }
}
