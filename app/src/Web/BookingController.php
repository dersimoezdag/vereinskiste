<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Ledger\AccountRepository;
use Vereinskiste\Ledger\BookingRepository;
use Vereinskiste\Ledger\BookingService;
use Vereinskiste\Ledger\CategoryRepository;
use Vereinskiste\Ledger\ValidationError;

final class BookingController extends AdminController
{
    public const PAGE_SIZE = 25;

    private const FORM_FIELDS = [
        'account_id', 'booking_date', 'amount', 'direction', 'category_id', 'description', 'counterparty', 'receipt_no',
    ];

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
        return $this->renderForm($req, $res);
    }

    public function create(Request $req, Response $res): Response
    {
        $body = $this->body($req);
        $input = [];
        foreach (self::FORM_FIELDS as $field) {
            $input[$field] = $this->str($body, $field);
        }

        $user = $req->getAttribute('auth')->currentUser();
        try {
            $booking = (new BookingService(($this->pdo)()))->create($input, $user['id']);
        } catch (ValidationError $e) {
            return $this->renderForm($req, $res, 422, $input, $e->errors);
        }
        $this->flash($req, 'bookings.flash.created');
        return $this->redirect('/bookings/' . $booking['id']);
    }

    /** @param array{id:string} $args */
    public function show(Request $req, Response $res, array $args): Response
    {
        $booking = (new BookingRepository(($this->pdo)()))->find((int) $args['id']);
        if ($booking === null) {
            return $this->notFound($req);
        }
        return $req->getAttribute('view')->render($res, 'bookings/detail.twig', ['booking' => $booking]);
    }

    /**
     * @param array<string,string> $input
     * @param array<string,string> $errors
     */
    private function renderForm(Request $req, Response $res, int $status = 200, array $input = [], array $errors = []): Response
    {
        $pdo = ($this->pdo)();
        $input += [
            'account_id' => '', 'booking_date' => date('Y-m-d'), 'amount' => '', 'direction' => 'out',
            'category_id' => '', 'description' => '', 'counterparty' => '', 'receipt_no' => '',
        ];
        return $req->getAttribute('view')->render($res->withStatus($status), 'bookings/form.twig', [
            'input' => $input,
            'errors' => $errors,
            'accounts' => array_values(array_filter(
                (new AccountRepository($pdo))->all(),
                static fn (array $a): bool => $a['active']
            )),
            'categories' => array_values(array_filter(
                (new CategoryRepository($pdo))->all(),
                static fn (array $c): bool => $c['active']
            )),
        ]);
    }
}
