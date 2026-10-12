<?php
declare(strict_types=1);

namespace Vereinskiste\Receipts;

use PDO;
use RuntimeException;
use Throwable;
use Vereinskiste\Ledger\BookingNotFound;
use Vereinskiste\Ledger\ChangeLog;
use Vereinskiste\Ledger\ClosedPeriod;
use Vereinskiste\Ledger\ClosingService;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

/**
 * Belege (PDF/JPEG/PNG) einer Buchung: Pruefung, Ablage ausserhalb des Webroots, Metadaten in `receipts`.
 *
 * Dateien liegen flach im Verzeichnis `$dir` unter zufaelligen Namen; der Originalname ist nur Datum
 * (bereinigt, nie Teil eines Pfads). Typ per Inhalt (finfo), nie per Endung oder Client-MIME.
 * Die Datei wird nie komplett in den Speicher gelesen (copy/hash_file/finfo/getimagesize).
 *
 * Fehler der Eingabe: ValidationError ['receipt' => Textschluessel] bzw. ['reason' => ...].
 * Der Aufrufer besitzt eine eventuell offene aeussere Transaktion; rollt er sie nach `add`/`replace`
 * zurueck, bleibt die gespeicherte Datei als unreferenzierte Datei liegen (das Verzeichnis ist flach
 * und rein zufaellig benannt, ein Aufraeumen kann sie anhand der Tabelle erkennen).
 */
final class ReceiptStore
{
    public const MAX_BYTES = 10485760;
    public const MAX_PIXELS = 40000000;
    public const NAME_MAX = 200;
    public const ADDED_REASON = 'Beleg hinzugefügt';

    private const TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    private const HEIC_MIMES = ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'];
    private const HEIC_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1'];

    public function __construct(private PDO $pdo, private string $dir, private int $maxBytes = self::MAX_BYTES)
    {
        if (trim($dir) === '') {
            throw new \InvalidArgumentException('Das Beleg-Verzeichnis darf nicht leer sein.');
        }
    }

    /**
     * Neuer Beleg an einer Buchung (auch storniert oder abgeschlossen). Schreibt einen Verlaufseintrag,
     * ausser `$logChange` ist false: dann entsteht der Beleg zusammen mit der Buchung (selbe Anfrage und
     * Transaktion) und die Buchung gilt nicht als "geaendert".
     *
     * @return array{id:int,booking_id:int,original_name:string,mime:string,size:int,sha256:string,
     *               storage_name:string,uploaded_by:int,uploaded_at:string,replaced_at:?string}
     * @throws ValidationError
     * @throws BookingNotFound
     */
    public function add(int $bookingId, string $tmpPath, string $originalName, int $userId, bool $logChange = true): array
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM bookings WHERE id = ?');
        $stmt->execute([$bookingId]);
        if ($stmt->fetchColumn() === false) {
            throw new BookingNotFound($bookingId);
        }

        $file = $this->ingest($tmpPath);
        $name = self::cleanName($originalName, $file['ext']);
        try {
            return Transaction::run($this->pdo, function () use ($bookingId, $file, $name, $userId, $logChange): array {
                $receipt = $this->insert($bookingId, $file, $name, $userId);
                if ($logChange) {
                    (new ChangeLog($this->pdo))->record(
                        $bookingId,
                        $userId,
                        [['field' => 'receipt', 'from' => null, 'to' => $name]],
                        self::ADDED_REASON
                    );
                }
                return $receipt;
            });
        } catch (Throwable $e) {
            @unlink($this->dir . '/' . $file['storage_name']);
            throw $e;
        }
    }

    /**
     * Beleg durch eine neue Datei ersetzen. Die alte Datei bleibt erhalten und downloadbar
     * (`replaced_at` gesetzt); der neue Beleg gehoert zur selben Buchung.
     *
     * @return array{id:int,booking_id:int,original_name:string,mime:string,size:int,sha256:string,
     *               storage_name:string,uploaded_by:int,uploaded_at:string,replaced_at:?string}
     * @throws ValidationError Grund fehlt, Datei ungueltig oder Beleg bereits ersetzt
     * @throws ReceiptNotFound
     * @throws ClosedPeriod wenn die Buchung im abgeschlossenen Zeitraum liegt
     */
    public function replace(int $receiptId, string $tmpPath, string $originalName, string $reason, int $userId): array
    {
        $reason = ChangeLog::normalizeReason($reason);
        $old = $this->find($receiptId) ?? throw new ReceiptNotFound($receiptId);
        if ($old['replaced_at'] !== null) {
            throw new ValidationError(['receipt' => 'validation.receipt.already_replaced']);
        }

        $file = $this->ingest($tmpPath);
        $name = self::cleanName($originalName, $file['ext']);
        try {
            return Transaction::run($this->pdo, function () use ($receiptId, $file, $name, $reason, $userId): array {
                $lock = $this->pdo->prepare('SELECT booking_id, original_name, replaced_at FROM receipts WHERE id = ? FOR UPDATE');
                $lock->execute([$receiptId]);
                $current = $lock->fetch();
                if ($current === false) {
                    throw new ReceiptNotFound($receiptId);
                }
                if ($current['replaced_at'] !== null) {
                    throw new ValidationError(['receipt' => 'validation.receipt.already_replaced']);
                }
                $bookingId = (int) $current['booking_id'];
                $this->assertReplaceAllowed($bookingId);

                $this->pdo->prepare('UPDATE receipts SET replaced_at = now() WHERE id = ?')->execute([$receiptId]);
                $receipt = $this->insert($bookingId, $file, $name, $userId);
                (new ChangeLog($this->pdo))->record(
                    $bookingId,
                    $userId,
                    [['field' => 'receipt', 'from' => (string) $current['original_name'], 'to' => $name]],
                    $reason
                );
                return $receipt;
            });
        } catch (Throwable $e) {
            @unlink($this->dir . '/' . $file['storage_name']);
            throw $e;
        }
    }

    /**
     * Zentrale Sperrstelle fuer das Ersetzen: Belege von Buchungen im abgeschlossenen Zeitraum bleiben
     * unveraendert; `add` ruft sie bewusst nicht auf (neue Belege sind nach dem Abschluss erlaubt).
     * Laeuft in der Transaktion von `replace`.
     *
     * @throws ClosedPeriod
     */
    private function assertReplaceAllowed(int $bookingId): void
    {
        $stmt = $this->pdo->prepare('SELECT booking_date::text FROM bookings WHERE id = ?');
        $stmt->execute([$bookingId]);
        $date = $stmt->fetchColumn();
        if ($date === false) {
            throw new BookingNotFound($bookingId);
        }
        (new ClosingService($this->pdo))->assertOpen((string) $date);
    }

    /**
     * Alle Belege einer Buchung, aelteste zuerst; ersetzte tragen `replaced_at`.
     *
     * @return list<array{id:int,booking_id:int,original_name:string,mime:string,size:int,sha256:string,
     *                    storage_name:string,uploaded_by:int,uploaded_by_name:string,uploaded_at:string,replaced_at:?string}>
     */
    public function forBooking(int $bookingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.booking_id, r.original_name, r.mime, r.size, r.sha256, r.storage_name, r.uploaded_by,
                    u.name AS uploaded_by_name, r.uploaded_at::text AS uploaded_at, r.replaced_at::text AS replaced_at
               FROM receipts r JOIN users u ON u.id = r.uploaded_by
              WHERE r.booking_id = ?
              ORDER BY r.id'
        );
        $stmt->execute([$bookingId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'booking_id' => (int) $row['booking_id'],
            'original_name' => (string) $row['original_name'],
            'mime' => (string) $row['mime'],
            'size' => (int) $row['size'],
            'sha256' => trim((string) $row['sha256']),
            'storage_name' => (string) $row['storage_name'],
            'uploaded_by' => (int) $row['uploaded_by'],
            'uploaded_by_name' => (string) $row['uploaded_by_name'],
            'uploaded_at' => (string) $row['uploaded_at'],
            'replaced_at' => $row['replaced_at'] === null ? null : (string) $row['replaced_at'],
        ], $stmt->fetchAll());
    }

    /**
     * Pfad, MIME und Anzeigename fuer den Download (auch ersetzte Belege).
     *
     * @return array{path:string,mime:string,name:string}
     * @throws ReceiptNotFound
     */
    public function open(int $receiptId): array
    {
        $receipt = $this->find($receiptId) ?? throw new ReceiptNotFound($receiptId);
        $path = $this->dir . '/' . $receipt['storage_name'];
        if (!is_file($path)) {
            throw new ReceiptNotFound($receiptId);
        }
        return ['path' => $path, 'mime' => $receipt['mime'], 'name' => $receipt['original_name']];
    }

    /** Anzeigename bereinigen: kein Pfad, keine Steuerzeichen, begrenzte Laenge, nie leer. */
    public static function cleanName(string $name, string $ext): string
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        }
        $parts = preg_split('~[/\\\\]~', $name) ?: [''];
        $name = (string) end($parts);
        $name = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $name);
        $name = ltrim(trim($name), '.');
        $name = trim(mb_substr($name, 0, self::NAME_MAX));
        return $name === '' ? 'beleg.' . $ext : $name;
    }

    /**
     * Kopiert die Eingabe in das Speicherverzeichnis (temporaerer Name), prueft die Kopie und
     * benennt sie in den zufaelligen Endnamen um. Bei jedem Fehler bleibt nichts zurueck.
     *
     * @return array{storage_name:string,mime:string,ext:string,size:int,sha256:string}
     */
    private function ingest(string $tmpPath): array
    {
        $size = @filesize($tmpPath);
        if ($size === false || !is_file($tmpPath)) {
            throw new RuntimeException('Hochgeladene Datei ist nicht lesbar.');
        }
        if ($size === 0) {
            throw new ValidationError(['receipt' => 'validation.receipt.empty']);
        }
        if ($size > $this->maxBytes) {
            throw new ValidationError(['receipt' => 'validation.receipt.too_large']);
        }

        if (!is_dir($this->dir) && !@mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            throw new RuntimeException('Beleg-Verzeichnis kann nicht angelegt werden.');
        }
        $temp = $this->dir . '/.tmp-' . bin2hex(random_bytes(8));
        if (!@copy($tmpPath, $temp)) {
            @unlink($temp);
            throw new RuntimeException('Beleg konnte nicht gespeichert werden.');
        }

        try {
            $meta = $this->inspect($temp);
            $hash = hash_file('sha256', $temp);
            if ($hash === false) {
                throw new RuntimeException('Pruefsumme des Belegs konnte nicht berechnet werden.');
            }
            $storageName = bin2hex(random_bytes(16)) . '.' . $meta['ext'];
            $final = $this->dir . '/' . $storageName;
            if (!@rename($temp, $final)) {
                throw new RuntimeException('Beleg konnte nicht gespeichert werden.');
            }
            // Nur der Besitzer und die Gruppe duerfen lesen; auf Plattformen ohne Modi schlaegt das still fehl.
            @chmod($final, 0640);
            clearstatcache(true, $final);
            $size = filesize($final);
            if ($size === false) {
                throw new RuntimeException('Beleg konnte nicht gespeichert werden.');
            }
        } catch (Throwable $e) {
            @unlink($temp);
            throw $e;
        }
        return ['storage_name' => $storageName, 'mime' => $meta['mime'], 'ext' => $meta['ext'], 'size' => $size, 'sha256' => $hash];
    }

    /**
     * Typ per Inhalt bestimmen und Bildabmessungen pruefen, ohne zu dekodieren.
     *
     * @return array{mime:string,ext:string}
     * @throws ValidationError
     */
    private function inspect(string $path): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($mime === false) {
            throw new ValidationError(['receipt' => 'validation.receipt.type']);
        }
        if (in_array($mime, self::HEIC_MIMES, true) || $this->hasHeicBrand($path)) {
            throw new ValidationError(['receipt' => 'validation.receipt.heic']);
        }
        if (!isset(self::TYPES[$mime])) {
            throw new ValidationError(['receipt' => 'validation.receipt.type']);
        }
        if ($mime !== 'application/pdf') {
            // getimagesize liest nur den Kopf der Datei, es dekodiert keine Bilddaten.
            $info = @getimagesize($path);
            $expected = $mime === 'image/png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
            if ($info === false || ($info[2] ?? null) !== $expected || $info[0] < 1 || $info[1] < 1) {
                throw new ValidationError(['receipt' => 'validation.receipt.type']);
            }
            if ($info[0] * $info[1] > self::MAX_PIXELS) {
                throw new ValidationError(['receipt' => 'validation.receipt.megapixels']);
            }
        }
        return ['mime' => $mime, 'ext' => self::TYPES[$mime]];
    }

    private function hasHeicBrand(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $head = (string) fread($fh, 12);
        fclose($fh);
        return strlen($head) === 12 && substr($head, 4, 4) === 'ftyp'
            && in_array(substr($head, 8, 4), self::HEIC_BRANDS, true);
    }

    /**
     * @param array{storage_name:string,mime:string,size:int,sha256:string} $file
     * @return array{id:int,booking_id:int,original_name:string,mime:string,size:int,sha256:string,
     *               storage_name:string,uploaded_by:int,uploaded_at:string,replaced_at:?string}
     */
    private function insert(int $bookingId, array $file, string $name, int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO receipts (booking_id, original_name, mime, size, sha256, storage_name, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             RETURNING id, uploaded_at::text AS uploaded_at'
        );
        $stmt->execute([$bookingId, $name, $file['mime'], $file['size'], $file['sha256'], $file['storage_name'], $userId]);
        $row = $stmt->fetch();
        return [
            'id' => (int) $row['id'],
            'booking_id' => $bookingId,
            'original_name' => $name,
            'mime' => $file['mime'],
            'size' => $file['size'],
            'sha256' => $file['sha256'],
            'storage_name' => $file['storage_name'],
            'uploaded_by' => $userId,
            'uploaded_at' => (string) $row['uploaded_at'],
            'replaced_at' => null,
        ];
    }

    /** @return array{booking_id:int,original_name:string,mime:string,storage_name:string,replaced_at:?string}|null */
    private function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT booking_id, original_name, mime, storage_name, replaced_at::text AS replaced_at FROM receipts WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return [
            'booking_id' => (int) $row['booking_id'],
            'original_name' => (string) $row['original_name'],
            'mime' => (string) $row['mime'],
            'storage_name' => (string) $row['storage_name'],
            'replaced_at' => $row['replaced_at'] === null ? null : (string) $row['replaced_at'],
        ];
    }
}
