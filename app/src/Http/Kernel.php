<?php
declare(strict_types=1);

namespace Vereinskiste\Http;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Factory\AppFactory;

final class Kernel
{
    /** @param array<string,mixed> $config */
    public static function create(array $config): App
    {
        $app = AppFactory::create();
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware((bool) ($config['debug'] ?? false), true, true);

        $app->get('/health', function (Request $req, Response $res): Response {
            $res->getBody()->write('ok');
            return $res->withHeader('Content-Type', 'text/plain; charset=utf-8');
        });

        return $app;
    }
}
