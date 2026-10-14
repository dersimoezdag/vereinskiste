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
    public function __construct(private Closure $pdo, private string $migrationDir, private ?\Vereinskiste\Update\Runtime $runtime=null)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($this->runtime?->maintenance()!==null) {
            if (str_starts_with($path,'/settings/auto-update') || in_array($path,['/login','/logout'],true)
                || (preg_match('~^/_update-probe/([a-f0-9]{64})\z~',$path,$match) && $this->runtime->probeAllowed($match[1]))) {
                return $handler->handle($request);
            }
            return $request->getAttribute('view')->render((new Response())->withStatus(503)->withHeader('Retry-After','10'), 'install/maintenance.twig');
        }
        if (in_array($path, ['/login', '/logout', '/update', '/settings', '/settings/update', '/settings/check-updates', '/health'], true)
            || str_starts_with($path, '/settings/backup')
            || !(new Updater(($this->pdo)(), $this->migrationDir))->needsUpdate()) {
            return $handler->handle($request);
        }
        return $request->getAttribute('view')->render((new Response())->withStatus(503)
            ->withHeader('Retry-After', '300')->withHeader('Cache-Control', 'no-store'), 'install/maintenance.twig');
    }
}
