<?php
declare(strict_types=1);

namespace Vereinskiste\Receipts;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Vereinskiste\Ledger\ValidationError;

/**
 * Liest hochgeladene Belegdateien aus einer Anfrage.
 *
 * - `fromRequest`: genau eine Datei (Feld `receipt`, alternativ `receipt_camera`), z. B. fuer Ersetzen, Logo, Import.
 * - `allFromRequest`: beliebig viele Dateien aus der Dropzone (Feld `receipts[]`) plus die Einzelfelder.
 */
final class UploadInput
{
    public const FIELDS = ['receipt', 'receipt_camera'];
    public const MULTI_FIELD = 'receipts';
    public const MAX_FILES = 20;

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
            if ($file instanceof UploadedFileInterface && $file->getError() !== UPLOAD_ERR_NO_FILE) {
                return self::read($file);
            }
        }
        return null;
    }

    /**
     * Alle gewaehlten Dateien (leere Auswahl ergibt eine leere Liste).
     *
     * @return list<array{path:string,name:string}>
     * @throws ValidationError ['receipt' => Textschluessel] bei fehlgeschlagenem Upload oder zu vielen Dateien
     */
    public static function allFromRequest(Request $req): array
    {
        $uploaded = $req->getUploadedFiles()[self::MULTI_FIELD] ?? [];
        $uploaded = is_array($uploaded) ? $uploaded : [$uploaded];
        $out = [];
        foreach ($uploaded as $file) {
            if ($file instanceof UploadedFileInterface && $file->getError() !== UPLOAD_ERR_NO_FILE) {
                $out[] = self::read($file);
            }
        }
        $single = self::fromRequest($req);
        if ($single !== null) {
            array_unshift($out, $single);
        }
        if (count($out) > self::MAX_FILES) {
            throw new ValidationError(['receipt' => 'validation.receipt.too_many']);
        }
        return $out;
    }

    /** @return array{path:string,name:string} */
    private static function read(UploadedFileInterface $file): array
    {
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
}
