<?php
declare(strict_types=1);

namespace Vereinskiste\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Vereinskiste\Auth\AuthService;
use Vereinskiste\Http\ErrorPage;

/** Routen-Middleware: nicht angemeldet -> Redirect /login, fehlendes Recht -> 403. */
final class Auth implements MiddlewareInterface
{
    private function __construct(private ?string $permission)
    {
    }

    public static function requires(string $permission): self
    {
        return new self($permission);
    }

    /** Nur Anmeldung noetig, kein bestimmtes Recht. */
    public static function authenticated(): self
    {
        return new self(null);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var AuthService $auth */
        $auth = $request->getAttribute('auth');
        if ($auth->currentUser() === null) {
            return (new Response())->withStatus(302)->withHeader('Location', '/login');
        }
        if ($this->permission !== null && !$auth->can($this->permission)) {
            return ErrorPage::render($request->getAttribute('view'), 403, 'error.403.title', 'error.403.text');
        }
        return $handler->handle($request);
    }
}
