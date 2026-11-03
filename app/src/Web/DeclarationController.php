<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Club\Profile;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\Declarations;

final class DeclarationController extends AdminController
{
    public function __construct(private Closure $pdo) {}

    public function index(Request $req, Response $res): Response
    {
        return $req->getAttribute('view')->render($res, 'settings/declarations.twig', [
            'declarations' => (new Declarations(($this->pdo)()))->all(),
        ]);
    }

    public function form(Request $req, Response $res, array $args): Response
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $declaration = $id === null ? null : (new Declarations(($this->pdo)()))->find($id);
        if ($id !== null && $declaration === null) {
            return $this->notFound($req);
        }
        return $this->page($req, $res, $declaration, $declaration ?? ['title' => '', 'content' => '', 'kind' => 'confirmation', 'signature_required' => true]);
    }

    public function save(Request $req, Response $res, array $args): Response
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $repo = new Declarations(($this->pdo)());
        $declaration = $id === null ? null : $repo->find($id);
        if ($id !== null && $declaration === null) {
            return $this->notFound($req);
        }
        $input = $this->body($req);
        try {
            $repo->save($id, $input, $this->userId($req));
        } catch (ValidationError $e) {
            $values = ['title' => $this->str($input, 'title'), 'content' => $this->str($input, 'content'),
                'kind' => $this->str($input, 'kind'), 'signature_required' => ($input['signature_required'] ?? '') === '1'];
            return $this->page($req, $res->withStatus(422), $declaration, $values, $e->errors);
        }
        $this->flash($req, 'foundation.saved');
        return $this->redirect('/settings/declarations');
    }

    public function move(Request $req, Response $res, array $args): Response
    {
        (new Declarations(($this->pdo)()))->move((int) $args['id'], $this->str($this->body($req), 'direction'), $this->userId($req));
        return $this->redirect('/settings/declarations');
    }

    public function toggle(Request $req, Response $res, array $args): Response
    {
        try {
            (new Declarations(($this->pdo)()))->setActive((int) $args['id'], $this->str($this->body($req), 'active') === '1', $this->userId($req));
        } catch (ValidationError) {
            return $this->notFound($req);
        }
        $this->flash($req, 'foundation.saved');
        return $this->redirect('/settings/declarations');
    }

    /** @param array<string,mixed>|null $declaration @param array<string,mixed> $values @param array<string,string> $errors */
    private function page(Request $req, Response $res, ?array $declaration, array $values, array $errors = []): Response
    {
        $club = (string) ((new Profile(($this->pdo)()))->get()['data']['name'] ?? '');
        $preview = $declaration === null ? null : ['title' => Declarations::render($declaration['title'], $club, false),
            'content' => Declarations::render($declaration['content'], $club, true)] + $declaration;
        return $req->getAttribute('view')->render($res, 'settings/declaration_form.twig', [
            'declaration' => $declaration, 'values' => $values, 'errors' => $errors, 'preview' => $preview, 'kinds' => Declarations::KINDS,
        ]);
    }
}
