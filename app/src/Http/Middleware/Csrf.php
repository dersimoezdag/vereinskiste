<?php
declare(strict_types=1);

namespace Vereinskiste\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\Twig;
use Vereinskiste\Http\ErrorPage;
use Vereinskiste\Http\Session\SessionStore;

/** Prueft das versteckte Feld `_csrf` bei jeder zustandsaendernden Anfrage. */
final class Csrf implements MiddlewareInterface
{
    public const FIELD = '_csrf';
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private SessionStore $store, private Twig $view)
    {
    }

    public static function token(SessionStore $store): string
    {
        $token = $store->get('csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $store->set('csrf', $token);
        }
        return $token;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        $expected = $this->store->get('csrf');
        $body = $request->getParsedBody();
        $given = is_array($body) ? ($body[self::FIELD] ?? null) : null;

        if (!is_string($expected) || $expected === '' || !is_string($given) || !hash_equals($expected, $given)) {
            return ErrorPage::render($this->view, 403, 'error.csrf.title', 'error.csrf.text');
        }
        return $handler->handle($request);
    }
}
