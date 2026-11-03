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

final class FundingController extends AdminController
{
    public function __construct(private Closure $pdo) {}

    public function index(Request $req, Response $res): Response
    {
        return $req->getAttribute('view')->render($res, 'funding/index.twig', [
            'applications' => (new FundingRepository(($this->pdo)()))->list(),
        ]);
    }

    public function create(Request $req, Response $res): Response
    {
        $event = $req->getQueryParams()['event_id'] ?? '';
        return $this->form($req, $res, null, ['status' => 'draft', 'event_id' => is_string($event) ? $event : '']);
    }

    public function show(Request $req, Response $res, array $args): Response
    {
        $application = (new FundingRepository(($this->pdo)()))->find((int) $args['id']);
        return $application === null ? $this->notFound($req) : $this->form($req, $res, $application, $application);
    }

    public function save(Request $req, Response $res, array $args = []): Response
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $body = $this->body($req);
        $repo = new FundingRepository(($this->pdo)());
        $application = $id === null ? null : $repo->find($id);
        if ($id !== null && $application === null) { return $this->notFound($req); }
        try {
            $saved = $repo->save($id, $body, Fields::version($body['version'] ?? null), $this->userId($req));
        } catch (ValidationError $e) {
            return $this->form($req, $res->withStatus(422), $application, $body, $e->errors);
        } catch (Conflict) {
            return $this->form($req, $res->withStatus(409), $application, $body, ['version' => 'foundation.conflict']);
        }
        $this->flash($req, 'foundation.saved');
        return $this->redirect('/funding/' . $saved);
    }

    public function action(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $repo = new FundingRepository(($this->pdo)());
        $application = $repo->find($id);
        if ($application === null) { return $this->notFound($req); }
        try {
            if ($args['action'] === 'delete') {
                $repo->delete($id);
                $this->flash($req, 'funding.deleted');
                return $this->redirect('/funding');
            }
            $repo->addItem($id, $this->body($req));
        } catch (ValidationError $e) {
            return $this->form($req, $res->withStatus(422), $application, $application, $e->errors);
        }
        return $this->redirect('/funding/' . $id);
    }

    public function removeItem(Request $req, Response $res, array $args): Response
    {
        (new FundingRepository(($this->pdo)()))->removeItem((int) $args['id'], (int) $args['item']);
        return $this->redirect('/funding/' . (int) $args['id']);
    }

    private function form(Request $req, Response $res, ?array $application, array $input, array $errors = []): Response
    {
        $pdo = ($this->pdo)();
        $repo = new FundingRepository($pdo);
        $bookings = $application === null ? [] : (new BookingRepository($pdo))->search(['funding_id' => $application['id']], 50, 0);
        return $req->getAttribute('view')->render($res, 'funding/detail.twig', [
            'application' => $application, 'input' => $input, 'errors' => $errors,
            'statuses' => FundingRepository::STATUSES,
            'events' => (new EventRepository($pdo))->options(isset($application['event_id']) ? (int) $application['event_id'] : null),
            'items' => $application === null ? [] : $repo->items($application['id']),
            'bookings' => $bookings,
            'history' => $application === null ? [] : (new History($pdo))->for('funding', $application['id']),
        ]);
    }
}
