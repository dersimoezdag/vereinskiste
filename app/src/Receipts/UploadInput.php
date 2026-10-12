<?php
declare(strict_types=1);

namespace Vereinskiste\Receipts;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Vereinskiste\Ledger\ValidationError;

/** Liest die hochgeladene Belegdatei aus einer Anfrage (Feld `receipt`, alternativ `receipt_camera`). */
final class UploadInput
{
    public const FIELDS = ['receipt', 'receipt_camera'];

    /**
     * @return array{path:string,name:string}|null null, wenn keine Datei gewaehlt wurde
     * @throws ValidationError ['receipt' => Textschluessel] bei fehlgeschlagenem Upload
     */
    public static function fromRequest(Request $req): ?array
    {
        $files = $req->getUploadedFiles();
        $chosen = 0;
        foreach (self::FIELDS as $field) {
            $f = $files[$field] ?? null;
            if ($f instanceof UploadedFileInterface && $f->getError() !== UPLOAD_ERR_NO_FILE) {
                $chosen++;
            }
        }
        if ($chosen > 1) {
            throw new ValidationError(['receipt' => 'validation.receipt.both']);
        }
        foreach (self::FIELDS as $field) {
            $file = $files[$field] ?? null;
            if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $error = match ($file->getError()) {
                UPLOAD_ERR_OK => null,
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'validation.receipt.too_large',
                UPLOAD_ERR_PARTIAL => 'validation.receipt.partial',
                default => 'validation.receipt.upload_failed',
            };
            if ($error !== null) {
                throw new ValidationError(['receipt' => $error]);
            }
            $path = $file->getStream()->getMetadata('uri');
            if (!is_string($path) || $path === '') {
                throw new ValidationError(['receipt' => 'validation.receipt.upload_failed']);
            }
            return ['path' => $path, 'name' => (string) $file->getClientFilename()];
        }
        return null;
    }
}
