<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\ClubRoleRepository;
use Vereinskiste\Members\MemberRoleAssignments;

final class ClubRoleController extends AdminController
{
    public function __construct(private Closure $pdo) {}

    public function index(Request $req, Response $res): Response
    {
        return $this->renderList($req, $res);
    }

    public function create(Request $req, Response $res): Response
    {
        $body = $this->body($req);
        $name = $this->str($body, 'name');
        $desc = $this->str($body, 'description');
        $kind = $this->str($body, 'kind') ?: 'function';
        $single = !empty($body['single_holder']);

        $repo = new ClubRoleRepository(($this->pdo)());
        try {
            $repo->save(null, $name, $desc, true, 0, $this->userId($req), $single, $kind);
        } catch (ValidationError $e) {
            return $this->renderList($req, $res->withStatus(422), $body, $e->errors);
        }

        $this->flash($req, 'foundation.saved');
        return $this->redirect('/settings/club-roles');
    }

    public function update(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $body = $this->body($req);
        $name = $this->str($body, 'name');
        $desc = $this->str($body, 'description');
        $kind = $this->str($body, 'kind') ?: 'function';
        $single = !empty($body['single_holder']);
        $active = !empty($body['active']);
        $version = (int) ($body['version'] ?? 1);

        $repo = new ClubRoleRepository(($this->pdo)());
        try {
            $repo->save($id, $name, $desc, $active, $version, $this->userId($req), $single, $kind);
        } catch (ValidationError $e) {
            return $this->renderList($req, $res->withStatus(422), $body, $e->errors, $id);
        } catch (Conflict) {
            return $this->renderList($req, $res->withStatus(409), $body, ['version' => 'foundation.conflict'], $id);
        }

        $this->flash($req, 'foundation.saved');
        return $this->redirect('/settings/club-roles');
    }

    private function renderList(Request $req, Response $res, ?array $input = null, array $errors = [], ?int $errorRoleId = null): Response
    {
        $pdo = ($this->pdo)();
        $rolesRepo = new ClubRoleRepository($pdo);
        $assignments = new MemberRoleAssignments($pdo);

        $roles = $rolesRepo->list(true);
        $rolesWithHolders = [];
        foreach ($roles as $role) {
            $role['holders_list'] = $assignments->holders($role['id']);
            $rolesWithHolders[] = $role;
        }

        return $req->getAttribute('view')->render($res, 'settings/club_roles.twig', [
            'roles' => $rolesWithHolders,
            'input' => $input ?? [],
            'errors' => $errors,
            'error_role_id' => $errorRoleId,
            'settings_path' => '/settings/club-roles',
        ]);
    }
}
