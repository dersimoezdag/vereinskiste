<?php
declare(strict_types=1);

namespace Vereinskiste\Auth;

use Vereinskiste\Http\Session\SessionStore;

final class AuthService
{
    private const SESSION_KEY = 'user_id';
    private const CREDENTIAL_KEY = 'credential_hash';

    /** Konstanter bcrypt-Hash (Kosten wie PASSWORD_DEFAULT) eines nie verwendeten Zufallswerts. */
    public const DUMMY_HASH = '$2y$12$uGEfth2hShsaAdQNzkkIm.4tZ9O5mMKNhzeLtMEyZmpddchjfw/4S';

    /** @var array<string,mixed>|null|false false = noch nicht geladen */
    private array|null|false $current = false;

    /** @var \Closure(string,string):bool */
    private \Closure $verifier;
    private RateLimiter $limiter;

    /** @param (\Closure(string,string):bool)|null $verifier Standard: password_verify (injizierbar fuer Tests) */
    public function __construct(
        private UserRepository $users,
        private SessionStore $session,
        ?\Closure $verifier = null,
        ?RateLimiter $limiter = null
    ) {
        $this->verifier = $verifier ?? static fn (string $pw, string $hash): bool => password_verify($pw, $hash);
        $this->limiter = $limiter ?? new RateLimiter(dirname(__DIR__, 2) . '/storage/security');
    }

    /**
     * @return array{id:int,email:string,name:string,active:bool,roles:string[],permissions:string[]}|null
     */
    public function login(string $email, string $password, string $address = ''): ?array
    {
        if (!$this->limiter->take('login', $email, $address)) {
            throw new TooManyAttempts('Zu viele Anmeldeversuche.');
        }
        $user = $this->users->findByEmail(trim($email));
        // Immer genau eine Hash-Pruefung (unbekannter Nutzer: gegen den konstanten
        // Dummy-Hash gleicher Kosten, ohne neues Hashing), damit die Antwortzeit nichts verraet.
        $hash = $user['password_hash'] ?? self::DUMMY_HASH;
        $verified = ($this->verifier)($password, $hash);

        if ($user === null || !$verified || !$user['active']) {
            $this->users->audit('login_failed', null);
            return null;
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $this->users->updatePasswordHash($user['id'], $hash);
        }

        $this->session->regenerate();
        $this->session->remove('csrf');
        $this->session->set(self::SESSION_KEY, $user['id']);
        $this->session->set(self::CREDENTIAL_KEY, hash('sha256', $hash));
        $this->users->audit('login', $user['id']);
        $this->current = false;

        return $this->currentUser();
    }

    /**
     * Prueft ein Passwort gegen den Hash des angemeldeten Benutzers (derselbe Verifier wie bei der Anmeldung).
     */
    public function verifyPassword(string $password): bool
    {
        $current = $this->currentUser();
        $user = $current === null ? null : $this->users->findById($current['id']);
        $verified = ($this->verifier)($password, $user['password_hash'] ?? self::DUMMY_HASH);
        return $user !== null && $verified;
    }

    /**
     * Aendert das eigene Passwort, wenn das aktuelle stimmt. Die Regeln fuer das neue Passwort
     * (PasswordPolicy) prueft der Aufrufer vorher. Bei Erfolg: neue Sitzungs-ID und Protokolleintrag
     * `password_changed` ohne Passwort oder Hash.
     */
    public function changePassword(string $current, string $new): bool
    {
        if (!$this->verifyPassword($current)) {
            return false;
        }
        $user = $this->currentUser();
        if ($user === null) {
            return false;
        }
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $this->users->updatePasswordHash($user['id'], $hash);
        $this->session->set(self::CREDENTIAL_KEY, hash('sha256', $hash));
        $this->session->regenerate();
        $this->session->remove('csrf');
        $this->users->audit('password_changed', $user['id']);
        return true;
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
            $credential = $this->session->get(self::CREDENTIAL_KEY);
            if ($user !== null && $user['active'] && is_string($credential)
                && hash_equals(hash('sha256', $user['password_hash']), $credential)) {
                unset($user['password_hash']);
                $user['permissions'] = Permissions::for($user['roles']);
                $this->current = $user;
            } else {
                $this->session->remove(self::SESSION_KEY);
                $this->session->remove(self::CREDENTIAL_KEY);
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
