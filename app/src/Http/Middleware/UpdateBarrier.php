<?php
declare(strict_types=1);
namespace Vereinskiste\Http\Middleware;

use Closure;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Server\MiddlewareInterface;
use Vereinskiste\Update\Coordinator;
use Vereinskiste\Update\Runtime;

/** A shared advisory lock drains in-flight mutations before snapshot/migration. */
final class UpdateBarrier implements MiddlewareInterface
{
    public function __construct(private Closure $pdo,private Runtime $runtime) {}
    public function process(Request $request,Handler $handler): Response
    {
        $path=$request->getUri()->getPath();
        if (in_array($request->getMethod(),['GET','HEAD','OPTIONS'],true) || MaintenancePage::isUpdateRoute($path)) {
            return $handler->handle($request);
        }
        $db=($this->pdo)(); $locked=(bool)$db->query('SELECT pg_try_advisory_lock_shared(' . Coordinator::BARRIER . ')')->fetchColumn();
        try {
            if (!$locked || ($this->runtime->maintenance()!==null && !in_array($path,['/login','/logout'],true))) {
                return MaintenancePage::render($request);
            }
            return $handler->handle($request);
        } finally { if ($locked) { $db->query('SELECT pg_advisory_unlock_shared(' . Coordinator::BARRIER . ')'); } }
    }
}
