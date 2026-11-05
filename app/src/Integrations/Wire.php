<?php
declare(strict_types=1);
namespace Vereinskiste\Integrations;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

final class Wire
{
    public static function json(array $data, int $status = 200): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
    }

    public static function error(ApiError $error): ResponseInterface
    {
        $response = self::json(['error' => $error->data()], $error->status);
        if ($error->status === 401) { $response = $response->withHeader('WWW-Authenticate', 'Bearer realm="Vereinskiste"'); }
        return $response;
    }

    public static function body(ServerRequestInterface $request): array
    {
        return (array) json_decode((string) $request->getBody(), false, 32, JSON_THROW_ON_ERROR);
    }
}
