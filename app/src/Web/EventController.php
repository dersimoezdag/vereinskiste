<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Events\EventRepository;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Foundation\History;
use Vereinskiste\Funding\FundingRepository;
use Vereinskiste\Ledger\BookingRepository;
use Vereinskiste\Ledger\ValidationError;

final class EventController extends AdminController
{
    public function __construct(private Closure $pdo) {}

    public function index(Request $req, Response $res): Response
    {
        $kind = ($req->getQueryParams()['view'] ?? '') === 'templates' ? 'template' : 'event';
        return $req->getAttribute('view')->render($res, 'events/index.twig', [
            'kind' => $kind,
            'events' => (new EventRepository(($this->pdo)()))->list($kind),
        ]);
    }

    public function create(Request $req, Response $res): Response
    {
        $kind = ($req->getQueryParams()['kind'] ?? '') === 'template' ? 'template' : 'event';
        return $req->getAttribute('view')->render($res, 'events/new.twig', ['kind' => $kind, 'input' => [], 'errors' => []]);
    }

    public function show(Request $req, Response $res, array $args, array $errors = [], ?array $input = null): Response
    {
        $pdo = ($this->pdo)();
        $repo = new EventRepository($pdo);
        $event = $repo->find((int) $args['id']);
        if ($event === null) { return $this->notFound($req); }
        $bookings = new BookingRepository($pdo);
        $filter = ['event_id' => $event['id']];
        return $req->getAttribute('view')->render($res, 'events/detail.twig', [
            'event' => $event, 'input' => $input ?? $event, 'errors' => $errors,
            'budget' => $repo->budgetItems($event['id']),
            'tasks' => $repo->tasks($event),
            'totals' => $repo->totals($event['id']),
            'bookings' => $bookings->search($filter, 50, 0),
            'bookings_total' => $bookings->count($filter),
            'funding' => (new FundingRepository($pdo))->forEvent($event['id']),
            'members' => $pdo->query('SELECT id, name FROM members WHERE anonymized_at IS NULL ORDER BY lower(name), id LIMIT 1000')->fetchAll(),
            'history' => (new History($pdo))->for('event', $event['id']),
        ]);
    }

    public function save(Request $req, Response $res, array $args = []): Response
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $body = $this->body($req);
        $repo = new EventRepository(($this->pdo)());
        if ($id !== null && $repo->find($id) === null) { return $this->notFound($req); }
        try {
            $saved = $repo->save($id, $body, Fields::version($body['version'] ?? null), $this->userId($req));
        } catch (ValidationError $e) {
            if ($id !== null) { return $this->show($req, $res->withStatus(422), $args, $e->errors, $body); }
            return $req->getAttribute('view')->render($res->withStatus(422), 'events/new.twig', [
                'kind' => ($body['kind'] ?? '') === 'template' ? 'template' : 'event', 'input' => $body, 'errors' => $e->errors]);
        } catch (Conflict) {
            return $this->show($req, $res->withStatus(409), $args, ['version' => 'foundation.conflict'], $body);
        }
        $this->flash($req, 'foundation.saved');
        return $this->redirect('/events/' . $saved);
    }

    public function action(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $body = $this->body($req);
        $repo = new EventRepository(($this->pdo)());
        if ($repo->find($id) === null) { return $this->notFound($req); }
        try {
            switch ($args['action']) {
                case 'clone':
                    $created = $repo->duplicate($id, $body, $this->userId($req));
                    $this->flash($req, 'events.cloned');
                    return $this->redirect('/events/' . $created);
                case 'delete':
                    $kind = $repo->find($id)['kind'];
                    $repo->delete($id);
                    $this->flash($req, 'events.deleted');
                    return $this->redirect($kind === 'template' ? '/events?view=templates' : '/events');
                case 'budget':
                    $repo->addBudgetItem($id, $body);
                    break;
                default:
                    $repo->addTask($id, $body);
            }
        } catch (ValidationError $e) {
            return $this->show($req, $res->withStatus(422), $args, $e->errors);
        }
        return $this->redirect('/events/' . $id);
    }

    public function itemAction(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $item = (int) $args['item'];
        $repo = new EventRepository(($this->pdo)());
        match ($args['action']) {
            'budget-remove' => $repo->removeBudgetItem($id, $item),
            'task-toggle' => $repo->toggleTask($id, $item),
            default => $repo->removeTask($id, $item),
        };
        return $this->redirect('/events/' . $id);
    }
}
