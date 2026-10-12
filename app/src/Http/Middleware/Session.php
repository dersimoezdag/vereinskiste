<?php
declare(strict_types=1);

namespace Vereinskiste\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Vereinskiste\Http\Session\SessionStore;

/** Konfiguriert das Cookie (Secure nur bei HTTPS) und gibt die Sitzung am Ende frei. */
final class Session implements MiddlewareInterface
{
    public function __construct(private SessionStore $store)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->store->configure(self::isHttps($request));
        try {
            return $handler->handle($request);
        } finally {
            $this->store->commit();
        }
    }

    public static function isHttps(ServerRequestInterface $request): bool
    {
        $server = $request->getServerParams();
        return $request->getUri()->getScheme() === 'https'
            || (isset($server['HTTPS']) && $server['HTTPS'] !== '' && strtolower((string) $server['HTTPS']) !== 'off')
            || strtolower($request->getHeaderLine('X-Forwarded-Proto')) === 'https';
    }
}
