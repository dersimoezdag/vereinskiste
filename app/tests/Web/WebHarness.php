<?php
declare(strict_types=1);

namespace Vereinskiste\Tests\Web;

use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Vereinskiste\Http\Kernel;
use Vereinskiste\Http\Session\ArraySessionStore;

/**
 * Kleiner HTTP-Rahmen fuer Seitentests: Kernel mit Test-PDO, Anmeldung per Formular, CSRF-Token.
 * Erwartet die Eigenschaft `$pdo` (PDO) in der verwendenden Testklasse.
 */
trait WebHarness
{
    private App $app;
    private ArraySessionStore $store;

    private function bootApp(): void
    {
        $this->store = new ArraySessionStore();
        $this->app = Kernel::create(['require_https' => false, 'security_dir' => $this->securityDir(), 'pdo' => $this->pdo, 'session_store' => $this->store]);
    }

    /** @param string[] $roles */
    private function webUser(string $email, array $roles, string $name = 'Test Person'): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO users (email, name, password_hash) VALUES (?, ?, ?) RETURNING id');
        $stmt->execute([$email, $name, password_hash('richtig-richtig', PASSWORD_DEFAULT)]);
        $id = (int) $stmt->fetchColumn();
        foreach ($roles as $role) {
            $this->pdo->prepare('INSERT INTO user_roles (user_id, role) VALUES (?, ?)')->execute([$id, $role]);
        }
        return $id;
    }

    /** @param string[] $roles */
    private function loginAs(array $roles, string $email = 'wer@example.org', string $name = 'Test Person'): int
    {
        $id = $this->webUser($email, $roles, $name);
        $res = $this->app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', '/login')->withParsedBody([
                '_csrf' => $this->csrf(), 'email' => $email, 'password' => 'richtig-richtig',
            ])
        );
        $this->assertSame(302, $res->getStatusCode());
        return $id;
    }

    private function csrf(): string
    {
        $html = (string) $this->get('/login')->getBody();
        if ($html === '') {
            $html = (string) $this->get('/')->getBody();
        }
        $this->assertSame(1, preg_match('/name="_csrf" value="([^"]+)"/', $html, $m), 'csrf field missing');
        return $m[1];
    }

    private function get(string $path): ResponseInterface
    {
        return $this->app->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
    }

    /** @param array<string,string> $body */
    private function postForm(string $path, array $body): ResponseInterface
    {
        return $this->app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', $path)->withParsedBody(['_csrf' => $this->csrf()] + $body)
        );
    }
}
