<?php
declare(strict_types=1);

namespace Vereinskiste\Auth;

use Vereinskiste\Http\Session\SessionStore;

final class AuthService
{
    private const SESSION_KEY = 'user_id';

    /** @var array<string,mixed>|null|false false = noch nicht geladen */
    private array|null|false $current = false;

    public function __construct(private UserRepository $users, private SessionStore $session)
    {
    }

    /**
     * @return array{id:int,email:string,name:string,active:bool,roles:string[],permissions:string[]}|null
     */
    public function login(string $email, string $password): ?array
    {
        $user = $this->users->findByEmail(trim($email));
        // Immer genau eine Hash-Pruefung, damit Antwortzeit und -text nichts verraten.
        $hash = $user['password_hash'] ?? password_hash('dummy', PASSWORD_DEFAULT);
        $verified = password_verify($password, $hash);

        if ($user === null || !$verified || !$user['active']) {
            $this->users->audit('login_failed', null);
            return null;
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->users->updatePasswordHash($user['id'], password_hash($password, PASSWORD_DEFAULT));
        }

        $this->session->regenerate();
        $this->session->remove('csrf');
        $this->session->set(self::SESSION_KEY, $user['id']);
        $this->users->audit('login', $user['id']);
        $this->current = false;

        return $this->currentUser();
    }

    public function logout(): void
    {
        $this->session->destroy();
        $this->current = null;
    }

    /**
     * Rolle und Aktiv-Status werden je Request frisch aus der DB gelesen.
     *
     * @return array{id:int,email:string,name:string,active:bool,roles:string[],permissions:string[]}|null
     */
    public function currentUser(): ?array
    {
        if ($this->current !== false) {
            return $this->current;
        }
        $this->current = null;
        $id = $this->session->get(self::SESSION_KEY);
        if (is_int($id)) {
            $user = $this->users->findById($id);
            if ($user !== null && $user['active']) {
                unset($user['password_hash']);
                $user['permissions'] = Permissions::for($user['roles']);
                $this->current = $user;
            } else {
                $this->session->remove(self::SESSION_KEY);
            }
        }
        return $this->current;
    }

    public function can(string $permission): bool
    {
        $user = $this->currentUser();
        return $user !== null && in_array($permission, $user['permissions'], true);
    }
}
