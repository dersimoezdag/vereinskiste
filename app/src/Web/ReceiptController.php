<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;
use Vereinskiste\Http\ErrorPage;
use Vereinskiste\Ledger\BookingNotFound;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Receipts\ReceiptNotFound;
use Vereinskiste\Receipts\ReceiptStore;
use Vereinskiste\Receipts\UploadInput;

/**
 * Beleg-Upload, -Ersetzen und -Download.
 *
 * Downloads werden immer als `attachment` mit `nosniff` und restriktiver CSP ausgeliefert:
 * auch eine trotz Pruefung eingeschleuste Datei wird so nie im Kontext der Anwendung gerendert.
 */
final class ReceiptController extends AdminController
{
    /** @param Closure():\PDO $pdo */
    public function __construct(private Closure $pdo, private string $receiptsDir)
    {
    }

    /** @param array{id:string} $args */
    public function upload(Request $req, Response $res, array $args): Response
    {
        $bookingId = (int) $args['id'];
        try {
            $upload = UploadInput::fromRequest($req)
                ?? throw new ValidationError(['receipt' => 'validation.receipt.none']);
            $this->store()->add($bookingId, $upload['path'], $upload['name'], $this->userId($req));
        } catch (ValidationError $e) {
            return $this->failed($req, $bookingId, $e);
        } catch (BookingNotFound) {
            return $this->notFound($req);
        }
        $this->flash($req, 'receipts.flash.added');
        return $this->redirect('/bookings/' . $bookingId . '#receipts');
    }

    /** @param array{id:string} $args */
    public function replace(Request $req, Response $res, array $args): Response
    {
        $receiptId = (int) $args['id'];
        $bookingId = $this->bookingOf($receiptId);
        if ($bookingId === null) {
            return $this->notFound($req);
        }
        try {
            $upload = UploadInput::fromRequest($req)
                ?? throw new ValidationError(['receipt' => 'validation.receipt.none']);
            $this->store()->replace(
                $receiptId, $upload['path'], $upload['name'], $this->str($this->body($req), 'reason'), $this->userId($req)
            );
        } catch (ValidationError $e) {
            return $this->failed($req, $bookingId, $e);
        } catch (ReceiptNotFound) {
            return $this->notFound($req);
        }
        $this->flash($req, 'receipts.flash.replaced');
        return $this->redirect('/bookings/' . $bookingId . '#receipts');
    }

    /** @param array{id:string} $args */
    public function download(Request $req, Response $res, array $args): Response
    {
        try {
            $file = $this->store()->open((int) $args['id']);
        } catch (ReceiptNotFound) {
            return $this->notFound($req);
        }
        $handle = fopen($file['path'], 'rb');
        $size = filesize($file['path']);
        if ($handle === false || $size === false) {
            return $this->notFound($req);
        }
        return $res
            ->withBody(new Stream($handle))
            ->withHeader('Content-Type', $file['mime'])
            ->withHeader('Content-Length', (string) $size)
            ->withHeader('Content-Disposition', self::contentDisposition($file['name']))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /** `attachment` mit ASCII-Rueckfall und RFC-5987-Name; keine Steuerzeichen oder Anfuehrungszeichen im Header. */
    public static function contentDisposition(string $name): string
    {
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
        $ascii = trim($ascii, '_.') === '' ? 'beleg' : $ascii;
        // rawurlencode laesst ' ( ) * stehen, RFC 5987 erlaubt sie in attr-char nicht (bzw. ' nicht).
        $encoded = strtr(rawurlencode($name), ["'" => '%27', '(' => '%28', ')' => '%29', '*' => '%2A']);
        return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . $encoded;
    }

    private function store(): ReceiptStore
    {
        return new ReceiptStore(($this->pdo)(), $this->receiptsDir);
    }

    private function bookingOf(int $receiptId): ?int
    {
        $stmt = ($this->pdo)()->prepare('SELECT booking_id FROM receipts WHERE id = ?');
        $stmt->execute([$receiptId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function userId(Request $req): int
    {
        return $req->getAttribute('auth')->currentUser()['id'];
    }

    private function failed(Request $req, int $bookingId, ValidationError $e): Response
    {
        $key = $e->errors['receipt'] ?? $e->errors['reason'] ?? 'validation.receipt.upload_failed';
        return ErrorPage::render(
            $req->getAttribute('view'), 422, 'receipts.error.title', $key, '/bookings/' . $bookingId . '#receipts',
            'bookings.back_to_booking'
        );
    }
}
