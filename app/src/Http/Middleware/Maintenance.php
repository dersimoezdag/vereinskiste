<?php
declare(strict_types=1);

namespace Vereinskiste\Http\Middleware;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Vereinskiste\Install\Updater;

final class Maintenance implements MiddlewareInterface
{
    public function __construct(private Closure $pdo, private string $migrationDir)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (in_array($path, ['/login', '/logout', '/update', '/health'], true)
            || !(new Updater(($this->pdo)(), $this->migrationDir))->needsUpdate()) {
            return $handler->handle($request);
        }
        return $request->getAttribute('view')->render((new Response())->withStatus(503)
            ->withHeader('Retry-After', '300')->withHeader('Cache-Control', 'no-store'), 'install/maintenance.twig');
    }
}
