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

        if (self::exceedsPostLimit($request)) {
            return ErrorPage::render($this->view, 413, 'error.413.title', 'error.413.text');
        }

        $expected = $this->store->get('csrf');
        $body = $request->getParsedBody();
        $given = is_array($body) ? ($body[self::FIELD] ?? null) : null;

        if (!is_string($expected) || $expected === '' || !is_string($given) || !hash_equals($expected, $given)) {
            return ErrorPage::render($this->view, 403, 'error.csrf.title', 'error.csrf.text');
        }
        return $handler->handle($request);
    }

    /**
     * Ueberschreitet ein Formular `post_max_size`, verwirft PHP Body und Dateien komplett; das Token fehlt
     * dann. Das ist kein CSRF-Fehler, sondern "Upload zu gross" und bekommt einen eigenen Hinweis.
     */
    private static function exceedsPostLimit(ServerRequestInterface $request): bool
    {
        $body = $request->getParsedBody();
        if (($body !== null && $body !== []) || $request->getUploadedFiles() !== []) {
            return false;
        }
        if (stripos($request->getHeaderLine('Content-Type'), 'multipart/form-data') !== 0) {
            return false;
        }
        $length = $request->getHeaderLine('Content-Length');
        $limit = self::iniBytes((string) ini_get('post_max_size'));
        return $limit > 0 && preg_match('/^\d+\z/', $length) === 1 && (float) $length > $limit;
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if (preg_match('/^\d+[kmg]?\z/i', $value) !== 1) {
            return 0;
        }
        $number = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
