<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Members;

use PHPUnit\Framework\TestCase;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\SignatureImage;
use Vereinskiste\Tests\Support\ApplicationInput;

final class SignatureImageTest extends TestCase
{
    public function test_valid_signature_is_reencoded_png(): void
    {
        $png = SignatureImage::fromDataUrl(ApplicationInput::signature());
        $this->assertSame("\x89PNG", substr($png, 0, 4));
        $this->assertSame([600, 160], array_slice(getimagesizefromstring($png) ?: [], 0, 2));
    }

    /** @return array<string,array{string}> */
    public static function invalid(): array
    {
        return [
            'kein data-url' => ['https://example.org/a.png'],
            'falscher typ' => ['data:image/jpeg;base64,' . base64_encode('x')],
            'kein base64' => ['data:image/png;base64,***'],
            'kein bild' => ['data:image/png;base64,' . base64_encode('kein png')],
            'leer' => [ApplicationInput::signature(600, 160, false)],
            'zu breit' => [ApplicationInput::signature(1300, 160)],
            'zu hoch' => [ApplicationInput::signature(600, 500)],
            'zu gross' => ['data:image/png;base64,' . base64_encode(str_repeat('a', 200001))],
        ];
    }

    /** @dataProvider invalid */
    public function test_invalid_signatures_are_rejected(string $value): void
    {
        try {
            SignatureImage::fromDataUrl($value);
            $this->fail('expected ValidationError');
        } catch (ValidationError $e) {
            $this->assertSame(['signature' => 'declarations.error.signature_invalid'], $e->errors);
        }
    }
}
