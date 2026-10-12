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
use Vereinskiste\Ledger\TransferService;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Ledger\VersionConflict;

final class BookingController extends AdminController
{
    public const PAGE_SIZE = 25;

    private const FORM_FIELDS = [
        'account_id', 'booking_date', 'amount', 'direction', 'category_id', 'description', 'counterparty', 'receipt_no',
    ];

    private const TRANSFER_FIELDS = ['from_account_id', 'to_account_id', 'booking_date', 'amount', 'description'];

    /** @param Closure():\PDO $pdo */
    public function __construct(private Closure $pdo)
    {
    }

    public function index(Request $req, Response $res): Response
    {
        $repo = new BookingRepository(($this->pdo)());
        $query = $req->getQueryParams();
        $filter = BookingRepository::normalizeFilter($query);

        $total = $repo->count($filter);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $rawPage = $query['page'] ?? '1';
        $page = is_string($rawPage) && preg_match('/^\d{1,9}$/', $rawPage) === 1 ? (int) $rawPage : 1;
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
        try {
            $booking = (new BookingService(($this->pdo)()))->create($input, $this->userId($req));
        } catch (ValidationError $e) {
            return $this->renderForm($req, $res->withStatus(422), 'bookings/form.twig', ['input' => $input, 'errors' => $e->errors]);
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

    public function showCorrection(Request $req, Response $res): Response
    {
        $of = $req->getQueryParams()['of'] ?? null;
        $original = null;
        $input = [];
        if (is_string($of) && preg_match('/^[1-9]\d{0,17}$/', $of) === 1) {
            $original = (new BookingRepository(($this->pdo)()))->find((int) $of);
            if ($original === null) {
                return $this->notFound($req);
            }
            $input = ['account_id' => (string) $original['account_id']];
        }
        return $this->renderForm($req, $res, 'bookings/correction.twig', [
            'input' => $input + ['description' => ''],
            'original' => $original,
            'correction_of_id' => $original === null ? '' : (string) $original['id'],
            'reason' => '',
        ]);
    }

    public function createCorrection(Request $req, Response $res): Response
    {
        $pdo = ($this->pdo)();
        $body = $this->body($req);
        $input = $this->formInput($body);
        $reason = $this->str($body, 'reason');
        $rawOf = $this->str($body, 'correction_of_id');
        // Unbrauchbarer Verweis wird zu 0 und vom Service als "gibt es nicht" gemeldet.
        $of = $rawOf === '' ? null : (preg_match('/^[1-9]\d{0,17}$/', $rawOf) === 1 ? (int) $rawOf : 0);

        try {
            $booking = (new BookingService($pdo))->createCorrection($input, $of, $reason, $this->userId($req));
        } catch (ValidationError $e) {
            return $this->renderForm($req, $res->withStatus(422), 'bookings/correction.twig', [
                'input' => $input,
                'errors' => $e->errors,
                'original' => $of === null ? null : (new BookingRepository($pdo))->find($of),
                'correction_of_id' => $rawOf,
                'reason' => $reason,
            ]);
        }
        $this->flash($req, 'bookings.flash.correction_created');
        return $this->redirect('/bookings/' . $booking['id']);
    }

    public function showTransfer(Request $req, Response $res): Response
    {
        return $this->renderTransfer($req, $res, [
            'from_account_id' => '', 'to_account_id' => '', 'booking_date' => date('Y-m-d'), 'amount' => '', 'description' => '',
        ]);
    }

    public function createTransfer(Request $req, Response $res): Response
    {
        $body = $this->body($req);
        $input = [];
        foreach (self::TRANSFER_FIELDS as $field) {
            $input[$field] = $this->str($body, $field);
        }
        $accountId = static fn (string $v): int => preg_match('/^[1-9]\d{0,17}$/', $v) === 1 ? (int) $v : 0;

        try {
            [$out] = (new TransferService(($this->pdo)()))->create(
                $accountId($input['from_account_id']),
                $accountId($input['to_account_id']),
                $input['amount'],
                $input['booking_date'],
                $input['description'],
                $this->userId($req)
            );
        } catch (ValidationError $e) {
            return $this->renderTransfer($req, $res->withStatus(422), $input, $e->errors);
        }
        $this->flash($req, 'bookings.flash.transfer_created');
        return $this->redirect('/bookings/' . $out['id']);
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

    /**
     * Formular mit Konto- und Kategorieauswahl. Angeboten werden aktive Eintraege; ein bereits
     * gewaehlter inaktiver Eintrag (Bearbeitung) bleibt waehlbar, damit er nicht stillschweigend wechselt.
     *
     * @param array<string,mixed> $context input?, errors?, booking?
     */
    private function renderForm(Request $req, Response $res, string $template, array $context = []): Response
    {
        $pdo = ($this->pdo)();
        $context['input'] = ($context['input'] ?? []) + [
            'account_id' => '', 'booking_date' => date('Y-m-d'), 'amount' => '', 'direction' => 'out',
            'category_id' => '', 'description' => '', 'counterparty' => '', 'receipt_no' => '',
        ];
        $context['errors'] ??= [];
        $keep = static fn (string $key): ?int => isset($context['booking'][$key]) ? (int) $context['booking'][$key] : null;
        $context['accounts'] = array_values(array_filter(
            (new AccountRepository($pdo))->all(),
            static fn (array $a): bool => $a['active'] || $a['id'] === $keep('account_id')
        ));
        $context['categories'] = array_values(array_filter(
            (new CategoryRepository($pdo))->all(),
            static fn (array $c): bool => $c['active'] || $c['id'] === $keep('category_id')
        ));
        return $req->getAttribute('view')->render($res, $template, $context);
    }

    /**
     * @param array<string,string> $input
     * @param array<string,string> $errors
     */
    private function renderTransfer(Request $req, Response $res, array $input, array $errors = []): Response
    {
        return $req->getAttribute('view')->render($res, 'bookings/transfer.twig', [
            'input' => $input,
            'errors' => $errors,
            'accounts' => array_values(array_filter(
                (new AccountRepository(($this->pdo)()))->all(),
                static fn (array $a): bool => $a['active']
            )),
        ]);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,string>
     */
    private function formInput(array $body): array
    {
        $input = [];
        foreach (self::FORM_FIELDS as $field) {
            $input[$field] = $this->str($body, $field);
        }
        return $input;
    }

    /** Version aus dem Hidden-Feld; Unbrauchbares wird 0 und fuehrt damit zum Versionskonflikt. */
    private function version(string $raw): int
    {
        return preg_match('/^[1-9]\d{0,8}$/', $raw) === 1 ? (int) $raw : 0;
    }

    private function userId(Request $req): int
    {
        return $req->getAttribute('auth')->currentUser()['id'];
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
