<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Auth\AuthService;
use Vereinskiste\Auth\PasswordPolicy;
use Vereinskiste\Auth\Permissions;
use Vereinskiste\Auth\UserNotFound;
use Vereinskiste\Auth\UserRepository;
use Vereinskiste\Ledger\TextInput;
use Vereinskiste\Ledger\ValidationError;

/**
 * Benutzerverwaltung (nur `users.manage`, Vorstand) und das eigene Passwort (jeder angemeldete Benutzer).
 */
final class UserController extends AdminController
{
    private const EMAIL_MAX = 254;

    /** @param Closure():\PDO $pdo */
    public function __construct(private Closure $pdo)
    {
    }

    public function index(Request $req, Response $res): Response
    {
        return $req->getAttribute('view')->render($res, 'admin/users.twig', [
            'users' => $this->repo()->all(),
            'me' => $this->actorId($req),
        ] + $this->roleHelp());
    }

    public function showNew(Request $req, Response $res): Response
    {
        $query = $req->getQueryParams();
        $memberId = isset($query['member']) && ctype_digit((string) $query['member']) ? (int) $query['member'] : null;
        $name = '';
        $email = '';
        if ($memberId !== null) {
            $member = (new \Vereinskiste\Members\MemberRepository(($this->pdo)()))->find($memberId);
            if ($member !== null) {
                $name = $member['name'];
                $email = $member['data']['email'] ?? '';
            }
        }
        return $this->renderForm($req, $res, 200, [
            'user_id' => null,
            'input' => ['name' => $name, 'email' => $email, 'roles' => [], 'active' => true, 'member_id' => $memberId],
        ]);
    }

    public function create(Request $req, Response $res): Response
    {
        $body = $this->body($req);
        $memberId = isset($body['member_id']) && ctype_digit((string) $body['member_id']) && (int) $body['member_id'] > 0
            ? (int) $body['member_id'] : null;
        $input = [
            'name' => $this->str($body, 'name'),
            'email' => UserRepository::normalizeEmail($this->str($body, 'email')),
            'roles' => [],
            'active' => true,
            'member_id' => $memberId,
        ];
        $errors = $this->profileErrors($input['name'], $input['email']);
        [$input['roles'], $rolesError] = $this->roles($body);
        if ($rolesError !== null) {
            $errors['roles'] = $rolesError;
        }
        $password = $this->raw($body, 'password');
        $passwordError = PasswordPolicy::check($password, $input['email'], $input['name']);
        if ($passwordError !== null) {
            $errors['password'] = $passwordError;
        }

        if ($errors === []) {
            try {
                \Vereinskiste\Ledger\Transaction::run(($this->pdo)(), function () use ($input, $password, $memberId, $req): void {
                    $userId = $this->repo()->create(
                        $input['name'],
                        $input['email'],
                        password_hash($password, PASSWORD_DEFAULT),
                        $input['roles'],
                        $this->actorId($req)
                    );
                    if ($memberId !== null) {
                        (new \Vereinskiste\Members\MemberUserLink(($this->pdo)()))->link($memberId, $userId, $this->actorId($req));
                    }
                });
            } catch (ValidationError $e) {
                if (isset($e->errors['user'])) {
                    $errors['member_id'] = $e->errors['user'];
                } else {
                    $errors = $e->errors;
                }
            }
        }
        if ($errors !== []) {
            return $this->renderForm($req, $res, 422, ['user_id' => null, 'input' => $input, 'errors' => $errors]);
        }
        $this->flash($req, 'users.flash.created');
        return $this->redirect('/users');
    }

    /** @param array{id:string} $args */
    public function showEdit(Request $req, Response $res, array $args): Response
    {
        $user = $this->repo()->findById((int) $args['id']);
        if ($user === null) {
            return $this->notFound($req);
        }
        return $this->renderForm($req, $res, 200, [
            'user_id' => $user['id'],
            'input' => [
                'name' => $user['name'],
                'email' => $user['email'],
                'roles' => $user['roles'],
                'active' => $user['active'],
                'member_id' => $user['member_id'],
            ],
        ]);
    }

    /** @param array{id:string} $args */
    public function update(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $repo = $this->repo();
        $existing = $repo->findById($id);
        if ($existing === null) {
            return $this->notFound($req);
        }

        $body = $this->body($req);
        $memberId = isset($body['member_id']) && ctype_digit((string) $body['member_id']) && (int) $body['member_id'] > 0
            ? (int) $body['member_id'] : null;
        $input = [
            'name' => $this->str($body, 'name'),
            'email' => UserRepository::normalizeEmail($this->str($body, 'email')),
            'roles' => [],
            'active' => ($body['active'] ?? '') === '1',
            'member_id' => $memberId,
        ];
        $errors = $this->profileErrors($input['name'], $input['email']);
        [$input['roles'], $rolesError] = $this->roles($body);
        if ($rolesError !== null) {
            $errors['roles'] = $rolesError;
        }

        $changed = [];
        if ($errors === []) {
            try {
                \Vereinskiste\Ledger\Transaction::run(($this->pdo)(), function () use ($repo, $id, $input, $existing, $memberId, $req, &$changed): void {
                    $changed = $repo->update(
                        $id, $input['name'], $input['email'], $input['roles'], $input['active'], $this->actorId($req)
                    );
                    $oldMemberId = $existing['member_id'];
                    if ($oldMemberId !== $memberId) {
                        $link = new \Vereinskiste\Members\MemberUserLink(($this->pdo)());
                        if ($oldMemberId !== null) {
                            $link->unlink($oldMemberId, $this->actorId($req));
                        }
                        if ($memberId !== null) {
                            $link->link($memberId, $id, $this->actorId($req));
                        }
                    }
                });
            } catch (UserNotFound) {
                return $this->notFound($req);
            } catch (ValidationError $e) {
                if (isset($e->errors['user'])) {
                    $errors['member_id'] = $e->errors['user'];
                } else {
                    $errors = $e->errors;
                }
            }
        }
        if ($errors !== []) {
            return $this->renderForm($req, $res, 422, ['user_id' => $id, 'input' => $input, 'errors' => $errors]);
        }

        $this->flash($req, 'users.flash.updated');
        // Hat sich jemand selbst die Verwaltungsrechte genommen, ist /users fuer ihn nicht mehr erreichbar.
        $lostAccess = $id === $this->actorId($req) && in_array('roles', $changed, true)
            && !in_array('users.manage', Permissions::for($repo->findById($id)['roles']), true);
        return $this->redirect($lostAccess ? '/' : '/users');
    }

    /** @param array{id:string} $args */
    public function resetPassword(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $repo = $this->repo();
        $user = $repo->findById($id);
        if ($user === null) {
            return $this->notFound($req);
        }

        // Das eigene Passwort nur ueber /account/password: dort gilt die Pruefung des aktuellen Passworts.
        $password = $this->raw($this->body($req), 'password');
        $error = $id === $this->actorId($req)
            ? 'validation.password.self_reset'
            : PasswordPolicy::check($password, $user['email'], $user['name']);
        if ($error === null) {
            try {
                $repo->resetPassword($id, password_hash($password, PASSWORD_DEFAULT), $this->actorId($req));
            } catch (UserNotFound) {
                return $this->notFound($req);
            }
            $this->flash($req, 'users.flash.password_reset');
            return $this->redirect('/users');
        }
        return $this->renderForm($req, $res, 422, [
            'user_id' => $id,
            'input' => [
                'name' => $user['name'], 'email' => $user['email'], 'roles' => $user['roles'], 'active' => $user['active'],
            ],
            'password_error' => $error,
        ]);
    }

    public function showOwnPassword(Request $req, Response $res): Response
    {
        return $req->getAttribute('view')->render($res, 'admin/password.twig', ['errors' => []]);
    }

    public function changeOwnPassword(Request $req, Response $res): Response
    {
        /** @var AuthService $auth */
        $auth = $req->getAttribute('auth');
        $user = $auth->currentUser();
        if ($user === null) {
            return $this->redirect('/login');
        }

        $body = $this->body($req);
        $current = $this->raw($body, 'current_password');
        $new = $this->raw($body, 'new_password');
        $confirm = $this->raw($body, 'confirm_password');

        $errors = [];
        if (!$auth->verifyPassword($current)) {
            $errors['current_password'] = 'validation.password.current_wrong';
        }
        $policy = PasswordPolicy::check($new, $user['email'], $user['name']);
        if ($policy !== null) {
            $errors['new_password'] = $policy;
        } elseif ($new === $current) {
            $errors['new_password'] = 'validation.password.same_as_current';
        } elseif ($new !== $confirm) {
            $errors['confirm_password'] = 'validation.password.mismatch';
        }

        if ($errors === [] && !$auth->changePassword($current, $new)) {
            $errors['current_password'] = 'validation.password.current_wrong';
        }
        if ($errors !== []) {
            return $req->getAttribute('view')
                ->render($res->withStatus(422), 'admin/password.twig', ['errors' => $errors]);
        }
        $this->flash($req, 'account.flash.password_changed');
        return $this->redirect('/');
    }

    /**
     * Name und E-Mail-Adresse formal pruefen (die E-Mail ist bereits normalisiert).
     *
     * @return array<string,string> Feld => Textschluessel
     */
    private function profileErrors(string $name, string $email): array
    {
        $errors = [];
        if (!UserRepository::isStorableText($name)) {
            $errors['name'] = 'validation.text.encoding';
        } elseif (TextInput::isBlank($name)) {
            $errors['name'] = 'validation.name.required';
        } elseif (mb_strlen($name) > self::NAME_MAX) {
            $errors['name'] = 'validation.name.too_long';
        }

        if (!UserRepository::isStorableText($email)) {
            $errors['email'] = 'validation.text.encoding';
        } elseif (
            strlen($email) > self::EMAIL_MAX
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || !str_contains(substr($email, (int) strrpos($email, '@')), '.')
        ) {
            $errors['email'] = 'validation.user.email.invalid';
        }
        return $errors;
    }

    /**
     * Gewaehlte Rollen aus dem Formular (`roles[]`), geprueft gegen die Whitelist.
     *
     * @param array<string,mixed> $body
     * @return array{0:string[],1:?string} Rollen (sortiert, ohne Duplikate) und Fehlerschluessel
     */
    private function roles(array $body): array
    {
        $given = $body['roles'] ?? [];
        if (!is_array($given)) {
            return [[], 'validation.user.roles.unknown'];
        }
        try {
            return [\Vereinskiste\Auth\RoleSelection::read($given), null];
        } catch (ValidationError $e) {
            return [[], $e->errors['roles']];
        }
    }

    /** Passwort-Eingaben werden nie getrimmt. */
    private function raw(array $body, string $key): string
    {
        return is_string($body[$key] ?? null) ? $body[$key] : '';
    }

    private function actorId(Request $req): int
    {
        return (int) $req->getAttribute('auth')->currentUser()['id'];
    }

    /** @param array<string,mixed> $context */
    private function renderForm(Request $req, Response $res, int $status, array $context): Response
    {
        $link = new \Vereinskiste\Members\MemberUserLink(($this->pdo)());
        $members = $link->linkableMembers();
        $currentMemberId = $context['input']['member_id'] ?? null;
        if ($currentMemberId !== null && !in_array($currentMemberId, array_column($members, 'id'), true)) {
            $m = (new \Vereinskiste\Members\MemberRepository(($this->pdo)()))->find($currentMemberId);
            if ($m !== null) {
                array_unshift($members, ['id' => $m['id'], 'member_no' => $m['member_no'], 'name' => $m['name']]);
            }
        }
        $context += $this->roleHelp() + [
            'errors' => [],
            'password_error' => null,
            'me' => $this->actorId($req),
            'linkable_members' => $members,
        ];
        return $req->getAttribute('view')->render($res->withStatus($status), 'admin/user_form.twig', $context);
    }

    private function roleHelp(): array
    {
        $rights = [];
        foreach (Permissions::ROLES as $role) {
            $rights[$role] = Permissions::for([$role]);
        }
        return ['roles' => Permissions::ROLES, 'permissions' => Permissions::ALL, 'role_permissions' => $rights];
    }

    private function repo(): UserRepository
    {
        return new UserRepository($this->pdo);
    }
}
