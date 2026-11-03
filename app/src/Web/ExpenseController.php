<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Events\EventRepository;
use Vereinskiste\Expenses\ExpenseRepository;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Foundation\History;
use Vereinskiste\Http\ErrorPage;
use Vereinskiste\Ledger\AccountRepository;
use Vereinskiste\Ledger\CategoryRepository;
use Vereinskiste\Ledger\ClosedPeriod;
use Vereinskiste\Ledger\ValidationError;

final class ExpenseController extends AdminController
{
    public function __construct(private Closure $pdo) {}

    public function index(Request $req, Response $res): Response
    {
        $query = $req->getQueryParams();
        $state = in_array($query['state'] ?? '', ['reimbursed', 'all'], true) ? $query['state'] : 'open';
        $repo = new ExpenseRepository(($this->pdo)());
        $pagination = Pagination::from($query, $repo->count($state), '/expenses', ['state' => $state], [50, 100, 200]);
        return $req->getAttribute('view')->render($res, 'expenses/index.twig', [
            'state' => $state,
            'expenses' => $repo->list($state, $pagination->page, $pagination->perPage),
            'open' => $repo->openSummary(),
            'pagination' => $pagination->view(),
        ]);
    }

    public function create(Request $req, Response $res): Response
    {
        return $this->form($req, $res, null, ['spent_on' => date('Y-m-d'), 'member_id' => is_string($req->getQueryParams()['member_id'] ?? null) ? $req->getQueryParams()['member_id'] : '']);
    }

    public function show(Request $req, Response $res, array $args): Response
    {
        $expense = (new ExpenseRepository(($this->pdo)()))->find((int) $args['id']);
        return $expense === null ? $this->notFound($req) : $this->form($req, $res, $expense, $expense);
    }

    public function save(Request $req, Response $res, array $args = []): Response
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $body = $this->body($req);
        $repo = new ExpenseRepository(($this->pdo)());
        $expense = $id === null ? null : $repo->find($id);
        if ($id !== null && $expense === null) { return $this->notFound($req); }
        try {
            $saved = $repo->save($id, $body, Fields::version($body['version'] ?? null), $this->userId($req));
        } catch (ValidationError $e) {
            return $this->form($req, $res->withStatus(422), $expense, $body, $e->errors);
        } catch (Conflict) {
            return $this->form($req, $res->withStatus(409), $expense, $body, ['version' => 'foundation.conflict']);
        }
        $this->flash($req, 'foundation.saved');
        return $this->redirect('/expenses/' . $saved);
    }

    public function action(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $repo = new ExpenseRepository(($this->pdo)());
        $expense = $repo->find($id);
        if ($expense === null) { return $this->notFound($req); }
        $body = $this->body($req);
        try {
            switch ($args['action']) {
                case 'reimburse':
                    $repo->reimburse($id, $body, $this->userId($req));
                    $this->flash($req, 'expenses.reimbursed_flash');
                    break;
                case 'link':
                    $repo->link($id, $this->str($body, 'booking_id'));
                    $this->flash($req, 'expenses.reimbursed_flash');
                    break;
                case 'unlink':
                    $repo->unlink($id);
                    $this->flash($req, 'expenses.unlinked_flash');
                    break;
                default:
                    $repo->delete($id);
                    $this->flash($req, 'expenses.deleted');
                    return $this->redirect('/expenses');
            }
        } catch (ValidationError $e) {
            return $this->form($req, $res->withStatus(422), $expense, $expense, $e->errors);
        } catch (ClosedPeriod $e) {
            return $this->closedPeriod($req, $e, '/expenses/' . $id);
        } catch (Conflict) {
            return ErrorPage::render($req->getAttribute('view'), 409, 'error.409.title', 'error.409.text', '/expenses', 'common.back');
        }
        return $this->redirect('/expenses/' . $id);
    }

    private function form(Request $req, Response $res, ?array $expense, array $input, array $errors = []): Response
    {
        $pdo = ($this->pdo)();
        $repo = new ExpenseRepository($pdo);
        $open = $expense !== null && !$expense['reimbursed'];
        return $req->getAttribute('view')->render($res, 'expenses/detail.twig', [
            'expense' => $expense, 'input' => $input, 'errors' => $errors,
            'members' => $pdo->query('SELECT id, name FROM members WHERE anonymized_at IS NULL ORDER BY lower(name), id LIMIT 1000')->fetchAll(),
            'events' => (new EventRepository($pdo))->options(isset($expense['event_id']) ? (int) $expense['event_id'] : null),
            'accounts' => array_values(array_filter((new AccountRepository($pdo))->all(), static fn (array $a): bool => $a['active'])),
            'categories' => array_values(array_filter((new CategoryRepository($pdo))->all(), static fn (array $c): bool => $c['active'])),
            'candidates' => $open ? $repo->candidates($expense) : [],
            'today' => date('Y-m-d'),
            'history' => $expense === null ? [] : (new History($pdo))->for('expense', $expense['id']),
        ]);
    }
}
