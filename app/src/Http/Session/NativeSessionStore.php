<?php
declare(strict_types=1);

namespace Vereinskiste\Http\Session;

/** PHP-Sitzungen mit gehaertetem Cookie; startet erst beim ersten Zugriff. */
final class NativeSessionStore implements SessionStore
{
    private bool $secure = false;
    private bool $started = false;

    public function configure(bool $secure): void
    {
        $this->secure = $secure;
    }

    public function get(string $key): mixed
    {
        $this->start();
        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $this->start();
        $_SESSION = [];
        session_destroy();
        $this->started = false;
        if (!headers_sent()) {
            setcookie(session_name(), '', [
                'expires' => 1,
                'path' => '/',
                'secure' => $this->secure,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
    }

    public function commit(): void
    {
        if ($this->started && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $this->started = false;
    }

    private function start(): void
    {
        if ($this->started) {
            return;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('vk_session');
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $this->secure,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            session_start();
        }
        $this->started = true;
    }
}
