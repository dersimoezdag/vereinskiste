<?php
declare(strict_types=1);

namespace Vereinskiste\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class TransportSecurity implements MiddlewareInterface
{
    public function __construct(private bool $required, private string $baseUrl = '') {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $https = Session::isHttps($request);
        if ($this->required && !$https && $request->getUri()->getPath() !== '/health') {
            $response = new Response(403);
            if (in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
                $uri = $request->getUri()->withScheme('https')->withPort(null);
                $origin = parse_url($this->baseUrl);
                if (is_array($origin) && ($origin['scheme'] ?? '') === 'https' && !empty($origin['host'])) {
                    $uri = $uri->withHost($origin['host'])->withPort($origin['port'] ?? null);
                }
                $response = $response->withStatus(308)->withHeader('Location', (string) $uri);
            }
        } else {
            $response = $handler->handle($request);
        }
        foreach (['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'"] as $name => $value) {
            if (!$response->hasHeader($name)) { $response = $response->withHeader($name, $value); }
        }
        if ($https) { $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000'); }
        return $response;
    }
}
