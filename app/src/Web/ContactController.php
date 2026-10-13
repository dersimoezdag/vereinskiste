<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Contacts\ContactRepository;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Foundation\History;
use Vereinskiste\Ledger\ValidationError;

final class ContactController extends AdminController
{
    public function __construct(private Closure $pdo) {}

    public function index(Request $req, Response $res): Response
    {
        $query = $req->getQueryParams();
        $search = $this->str($query, 'q');
        if (!mb_check_encoding($search, 'UTF-8')) { $search = ''; }
        $state = $this->str($query, 'state') ?: 'active';
        $page = max(1, min(100000, (int) $this->str($query, 'page')));
        return $req->getAttribute('view')->render($res, 'contacts/index.twig', [
            'contacts' => (new ContactRepository(($this->pdo)()))->list($search, $state, $page),
            'search' => $search, 'state' => $state, 'page' => $page,
        ]);
    }

    public function create(Request $req, Response $res): Response { return $this->render($req, $res); }

    public function show(Request $req, Response $res, array $args): Response
    {
        $contact = (new ContactRepository(($this->pdo)()))->find((int) $args['id']);
        return $contact === null ? $this->notFound($req) : $this->render($req, $res, $contact);
    }

    public function save(Request $req, Response $res, array $args = []): Response
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $repo = new ContactRepository(($this->pdo)());
        $contact = $id === null ? null : $repo->find($id);
        if ($id !== null && $contact === null) { return $this->notFound($req); }
        $body = $this->body($req);
        if ($id === null && ($body['duplicate_confirmed'] ?? '') !== '1'
            && $repo->duplicates($this->str($body, 'name'), $this->str($body, 'email')) !== []) {
            return $this->render($req, $res->withStatus(422), null, $body, ['duplicate_confirmed' => 'foundation.duplicates']);
        }
        try {
            $saved = $repo->save($id, $body, Fields::version($body['version'] ?? null),
                $this->userId($req), $this->str($body, 'reason'));
        } catch (ValidationError $e) {
            return $this->render($req, $res->withStatus(422), $contact, $body, $e->errors);
        } catch (Conflict) {
            return $this->render($req, $res->withStatus(409), $contact, $body, ['version' => 'foundation.conflict']);
        }
        $this->flash($req, 'foundation.saved');
        return $this->redirect('/contacts/' . $saved);
    }

    public function csv(Request $req, Response $res): Response
    {
        $stream = (new \Vereinskiste\Contacts\ContactExport(($this->pdo)()))->csv();
        return $res->withBody(new \Slim\Psr7\Stream($stream))->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="finanzkontakte.csv"');
    }

    private function render(Request $req, Response $res, ?array $contact = null, ?array $input = null, array $errors = []): Response
    {
        $defaults = $contact === null ? ['kind' => 'person', 'active' => '1', 'version' => 0]
            : $contact['data'] + ['kind' => $contact['kind'], 'active' => $contact['active'] ? '1' : '', 'version' => $contact['version']];
        $safe = [];
        foreach (($input ?? $defaults) as $key => $value) {
            $safe[$key] = (is_string($value) || is_int($value)) && mb_check_encoding((string) $value, 'UTF-8') ? (string) $value : '';
        }
        return $req->getAttribute('view')->render($res, 'contacts/detail.twig', [
            'contact' => $contact, 'input' => $safe, 'fields' => ContactRepository::FIELDS, 'errors' => $errors,
            'history' => $contact === null ? [] : (new History(($this->pdo)()))->for('contact', $contact['id']),
            'duplicates' => $contact === null && $input !== null
                ? (new ContactRepository(($this->pdo)()))->duplicates($this->str($input, 'name'), $this->str($input, 'email')) : [],
        ]);
    }
}
