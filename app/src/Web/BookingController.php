<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Http\ErrorPage;
use Vereinskiste\Ledger\AccountRepository;
use Vereinskiste\Ledger\BookingLocked;
use Vereinskiste\Ledger\BookingNotFound;
use Vereinskiste\Ledger\BookingRepository;
use Vereinskiste\Ledger\BookingService;
use Vereinskiste\Ledger\CategoryRepository;
use Vereinskiste\Ledger\ChangeLog;
use Vereinskiste\Ledger\ClosedPeriod;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\TransferService;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Ledger\VersionConflict;
use Vereinskiste\Receipts\ReceiptStore;
use Vereinskiste\Receipts\UploadInput;

final class BookingController extends LedgerController
{
    public const PAGE_SIZE = 25;

    /** @param Closure():\PDO $pdo */
    public function __construct(Closure $pdo, private string $receiptsDir)
    {
        parent::__construct($pdo);
    }

    public function index(Request $req, Response $res): Response
    {
        $repo = new BookingRepository(($this->pdo)());
        $query = $req->getQueryParams();
        $filter = BookingRepository::normalizeFilter($query);

        $total = $repo->count($filter);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $rawPage = $query['page'] ?? '1';
        $page = is_string($rawPage) && preg_match('/^\d{1,9}\z/', $rawPage) === 1 ? (int) $rawPage : 1;
        $page = min(max(1, $page), $pages);

        $base = http_build_query($filter);
        $link = static fn (int $n): string => '/bookings?' . $base . ($base === '' ? '' : '&') . 'page=' . $n;

        return $req->getAttribute('view')->render($res, 'bookings/list.twig', [
            'bookings' => $repo->search($filter, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'filter' => $filter + [
                'from' => '', 'to' => '', 'account_id' => '', 'category_id' => '', 'direction' => '',
                'kind' => '', 'status' => '', 'q' => '',
            ],
            'accounts' => (new AccountRepository(($this->pdo)()))->all(),
            'categories' => (new CategoryRepository(($this->pdo)()))->all(),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'prev_url' => $page > 1 ? $link($page - 1) : null,
            'next_url' => $page < $pages ? $link($page + 1) : null,
        ]);
    }

    public function showNew(Request $req, Response $res): Response
    {
        return $this->renderForm($req, $res, 'bookings/form.twig');
    }

    public function create(Request $req, Response $res): Response
    {
        $input = $this->formInput($this->body($req));
        $pdo = ($this->pdo)();
        $userId = $this->userId($req);
        try {
            // Optionale Belege (Dropzone): Buchung und alle Belege entstehen gemeinsam oder gar nicht.
            $uploads = UploadInput::allFromRequest($req);
            $booking = Transaction::run($pdo, function () use ($pdo, $input, $uploads, $userId): array {
                $created = (new BookingService($pdo))->create($input, $userId);
                if ($uploads !== []) {
                    (new ReceiptStore($pdo, $this->receiptsDir))->addMany($created['id'], $uploads, $userId, false);
                }
                return $created;
            });
        } catch (ValidationError $e) {
            return $this->renderForm($req, $res->withStatus(422), 'bookings/form.twig', ['input' => $input, 'errors' => $e->errors]);
        } catch (ClosedPeriod $e) {
            return $this->closedPeriod($req, $e);
        }
        $this->flash($req, 'bookings.flash.created');
        return $this->redirect('/bookings/' . $booking['id']);
    }

    /** @param array{id:string} $args */
    public function show(Request $req, Response $res, array $args): Response
    {
        $pdo = ($this->pdo)();
        $repo = new BookingRepository($pdo);
        $booking = $repo->find((int) $args['id']);
        if ($booking === null) {
            return $this->notFound($req);
        }

        $counterparts = [];
        if ($booking['transfer_id'] !== null) {
            $counterparts = array_values(array_filter(
                (new TransferService($pdo))->legs($booking['transfer_id']),
                static fn (array $leg): bool => $leg['id'] !== $booking['id']
            ));
        }

        return $req->getAttribute('view')->render($res, 'bookings/detail.twig', [
            'booking' => $booking,
            'history' => (new ChangeLog($pdo))->forBooking($booking['id']),
            'invoice_documents' => (new \Vereinskiste\Invoices\LinkedDocuments($pdo))->forBooking($booking['id']),
            'receipts' => (new ReceiptStore($pdo, $this->receiptsDir))->forBooking($booking['id']),
            'account_names' => array_column((new AccountRepository($pdo))->all(), 'name', 'id'),
            'category_names' => array_column((new CategoryRepository($pdo))->all(), 'name', 'id'),
            'original' => $booking['correction_of_id'] === null ? null : $repo->find($booking['correction_of_id']),
            'counterparts' => $counterparts,
        ]);
    }

    /** @param array{id:string} $args */
    public function showEdit(Request $req, Response $res, array $args): Response
    {
        $booking = (new BookingRepository(($this->pdo)()))->find((int) $args['id']);
        if ($booking === null) {
            return $this->notFound($req);
        }
        $locked = $this->lockReason($booking);
        if ($locked !== null) {
            return $this->locked($req, $booking['id'], $locked);
        }

        $input = [];
        foreach (self::FORM_FIELDS as $field) {
            $input[$field] = (string) ($booking[$field] ?? '');
        }
        $input['amount'] = str_replace('.', ',', $booking['amount']);
        return $this->renderEdit($req, $res, $booking, $input, (string) $booking['version'], '');
    }

    /** @param array{id:string} $args */
    public function update(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $booking = (new BookingRepository(($this->pdo)()))->find($id);
        if ($booking === null) {
            return $this->notFound($req);
        }
        $body = $this->body($req);
        $input = $this->formInput($body);
        $version = $this->str($body, 'version');
        $reason = $this->str($body, 'reason');

        try {
            $updated = (new BookingService(($this->pdo)()))
                ->update($id, $input, $reason, $this->version($version), $this->userId($req));
        } catch (ValidationError $e) {
            // Die eingereichte Version bleibt im Formular, damit ein zwischenzeitlicher Fremdzugriff nicht verschluckt wird.
            return $this->renderEdit($req, $res->withStatus(422), $booking, $input, $version, $reason, $e->errors);
        } catch (VersionConflict) {
            return $this->conflict($req, $id);
        } catch (BookingNotFound) {
            return $this->notFound($req);
        } catch (BookingLocked $e) {
            return $this->locked($req, $id, $e->textKey);
        } catch (ClosedPeriod $e) {
            return $this->closedPeriod($req, $e, '/bookings/' . $id);
        }
        $this->flash($req, $updated['version'] === $booking['version'] ? 'bookings.flash.unchanged' : 'bookings.flash.updated');
        return $this->redirect('/bookings/' . $id);
    }

    /** @param array{id:string} $args */
    public function cancel(Request $req, Response $res, array $args): Response
    {
        return $this->changeStatus($req, $res, (int) $args['id'], 'cancel');
    }

    /** @param array{id:string} $args */
    public function restore(Request $req, Response $res, array $args): Response
    {
        return $this->changeStatus($req, $res, (int) $args['id'], 'restore');
    }

    private function changeStatus(Request $req, Response $res, int $id, string $action): Response
    {
        $pdo = ($this->pdo)();
        $booking = (new BookingRepository($pdo))->find($id);
        if ($booking === null) {
            return $this->notFound($req);
        }
        $body = $this->body($req);
        $version = $this->str($body, 'version');
        $reason = $this->str($body, 'reason');
        $isTransfer = $booking['kind'] === 'transfer';

        try {
            $service = $isTransfer ? new TransferService($pdo) : new BookingService($pdo);
            $service->{$action}($id, $reason, $this->version($version), $this->userId($req));
        } catch (ValidationError $e) {
            return $req->getAttribute('view')->render($res->withStatus(422), 'bookings/cancel.twig', [
                'booking' => $booking, 'action' => $action, 'version' => $version, 'reason' => $reason, 'errors' => $e->errors,
            ]);
        } catch (VersionConflict) {
            return $this->conflict($req, $id);
        } catch (BookingNotFound) {
            return $this->notFound($req);
        } catch (BookingLocked $e) {
            return $this->locked($req, $id, $e->textKey);
        } catch (ClosedPeriod $e) {
            return $this->closedPeriod($req, $e, '/bookings/' . $id);
        }

        $flash = [
            'cancel' => $isTransfer ? 'bookings.flash.transfer_cancelled' : 'bookings.flash.cancelled',
            'restore' => $isTransfer ? 'bookings.flash.transfer_restored' : 'bookings.flash.restored',
        ];
        $this->flash($req, $flash[$action]);
        return $this->redirect('/bookings/' . $id);
    }

    /**
     * Warum die normale Bearbeitung fuer diese Buchung gesperrt ist (Textschluessel) oder null.
     *
     * @param array<string,mixed> $booking
     */
    private function lockReason(array $booking): ?string
    {
        if ($booking['kind'] === 'transfer') {
            return 'bookings.locked.transfer';
        }
        return $booking['status'] === 'cancelled' ? 'bookings.locked.cancelled' : null;
    }

    /**
     * @param array<string,mixed> $booking
     * @param array<string,string> $input
     * @param array<string,string> $errors
     */
    private function renderEdit(
        Request $req,
        Response $res,
        array $booking,
        array $input,
        string $version,
        string $reason,
        array $errors = []
    ): Response {
        return $this->renderForm($req, $res, 'bookings/edit.twig', [
            'booking' => $booking, 'input' => $input, 'errors' => $errors, 'version' => $version, 'reason' => $reason,
        ]);
    }

    /** Version aus dem Hidden-Feld; Unbrauchbares wird 0 und fuehrt damit zum Versionskonflikt. */
    private function version(string $raw): int
    {
        return preg_match('/^[1-9]\d{0,8}\z/', $raw) === 1 ? (int) $raw : 0;
    }

    private function conflict(Request $req, int $id): Response
    {
        return ErrorPage::render(
            $req->getAttribute('view'), 409, 'error.409.title', 'error.409.text', '/bookings/' . $id, 'bookings.reload'
        );
    }

    private function locked(Request $req, int $id, string $textKey): Response
    {
        return ErrorPage::render(
            $req->getAttribute('view'), 409, 'bookings.locked.title', $textKey, '/bookings/' . $id, 'bookings.back_to_booking'
        );
    }
}
