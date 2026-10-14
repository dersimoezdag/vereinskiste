<?php
declare(strict_types=1);

namespace Vereinskiste\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/** Wartungsantwort ohne Navigation, Fachabfragen oder JavaScript. */
final class MaintenancePage
{
    public static function isUpdateRoute(string $path): bool
    {
        return in_array($path, ['/settings/auto-update', '/settings/auto-update/status',
            '/settings/auto-update/start', '/settings/auto-update/step', '/settings/auto-update/cancel'], true);
    }

    public static function render(ServerRequestInterface $request, int $retry = 10, bool $updating = true): ResponseInterface
    {
        return $request->getAttribute('view')->render((new Response())->withStatus(503)
            ->withHeader('Retry-After', (string) $retry)->withHeader('Cache-Control', 'no-store'),
            'install/maintenance.twig', ['updating' => $updating]);
    }
}
