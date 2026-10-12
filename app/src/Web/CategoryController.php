<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Ledger\CategoryRepository;
use Vereinskiste\Ledger\NameAlreadyExists;

final class CategoryController extends AdminController
{
    /** @param Closure():\PDO $pdo */
    public function __construct(private Closure $pdo)
    {
    }

    public function index(Request $req, Response $res): Response
    {
        return $this->render($req, $res);
    }

    public function create(Request $req, Response $res): Response
    {
        $name = $this->str($this->body($req), 'name');
        $error = $this->check($name, null, fn () => $this->repo()->create($name, $this->userId($req)));
        if ($error !== null) {
            return $this->render($req, $res, 422, ['new_name' => $name, 'errors' => ['name' => $error]]);
        }
        $this->flash($req, 'categories.flash.created');
        return $this->redirect('/categories');
    }

    /** @param array{id:string} $args */
    public function rename(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->repo()->find($id) === null) {
            return $this->notFound($req);
        }
        $name = $this->str($this->body($req), 'name');
        $error = $this->check($name, $id, fn () => $this->repo()->rename($id, $name, $this->userId($req)));
        if ($error !== null) {
            return $this->render($req, $res, 422, [
                'edit_id' => $id, 'edit_name' => $name, 'errors' => ['name' => $error],
            ]);
        }
        $this->flash($req, 'categories.flash.renamed');
        return $this->redirect('/categories');
    }

    /** @param array{id:string} $args */
    public function toggle(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $repo = $this->repo();
        if ($repo->find($id) === null) {
            return $this->notFound($req);
        }
        $repo->toggleActive($id, $this->userId($req));
        $this->flash($req, 'categories.flash.toggled');
        return $this->redirect('/categories');
    }

    /**
     * Validiert den Namen und fuehrt bei Erfolg `$write` aus (die DB-Eindeutigkeit
     * faengt zusaetzlich Wettlaeufe ab).
     *
     * @return string|null Textschluessel des Fehlers
     */
    private function check(string $name, ?int $exceptId, Closure $write): ?string
    {
        $error = $this->nameError($name);
        if ($error === null && $this->repo()->nameExists($name, $exceptId)) {
            return 'validation.category.duplicate';
        }
        if ($error !== null) {
            return $error;
        }
        try {
            $write();
        } catch (NameAlreadyExists) {
            return 'validation.category.duplicate';
        }
        return null;
    }

    /** @param array<string,mixed> $context */
    private function render(Request $req, Response $res, int $status = 200, array $context = []): Response
    {
        $context += ['new_name' => '', 'edit_id' => null, 'edit_name' => '', 'errors' => []];
        $context['categories'] = $this->repo()->all();
        return $req->getAttribute('view')->render($res->withStatus($status), 'admin/categories.twig', $context);
    }

    private function repo(): CategoryRepository
    {
        return new CategoryRepository(($this->pdo)());
    }
}
