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
            if (in_array($path,['/login','/logout'],true)
                || (preg_match('~^/_update-probe/([a-f0-9]{64})\z~',$path,$match) && $this->runtime->probeAllowed($match[1]))) {
                return $handler->handle($request);
            }
            if ($request->getAttribute('auth')->can('settings.manage')) {
                if (MaintenancePage::isUpdateRoute($path)) { return $handler->handle($request); }
                if ($path === '/' && in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
                    return (new Response())->withStatus(302)->withHeader('Location', '/settings/auto-update');
                }
            }
            return MaintenancePage::render($request);
        }
        if (in_array($path, ['/login', '/logout', '/update', '/settings', '/settings/update', '/settings/check-updates', '/health'], true)
            || str_starts_with($path, '/settings/backup')
            || !(new Updater(($this->pdo)(), $this->migrationDir))->needsUpdate()) {
            return $handler->handle($request);
        }
        return MaintenancePage::render($request, 300, false);
    }
}
