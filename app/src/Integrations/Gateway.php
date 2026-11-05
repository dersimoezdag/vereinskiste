<?php
declare(strict_types=1);
namespace Vereinskiste\Integrations;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\StreamFactory;
use Vereinskiste\Auth\RateLimiter;

/** Bearer authentication before body parsing; session credentials never authorize API requests. */
final class Gateway implements MiddlewareInterface
{
    public function __construct(private Closure $pdo, private RateLimiter $limiter, private string $baseUrl = '') {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($path !== '/mcp' && !str_starts_with($path, '/api/v1/')) { return $handler->handle($request); }
        $protocol = $path === '/mcp' ? 'mcp' : 'api';
        try {
            $origin = $request->getHeaderLine('Origin');
            $base = $this->baseUrl !== '' ? parse_url($this->baseUrl) : parse_url((string) $request->getUri());
            $allowedOrigin = ($base['scheme'] ?? 'https') . '://' . ($base['host'] ?? '') . (isset($base['port']) ? ':' . $base['port'] : '');
            if ($origin !== '' && $origin !== $allowedOrigin) { throw new ApiError(403, 'origin_denied', 'Origin ist nicht freigegeben.'); }
            $authorization = $request->getHeaderLine('Authorization');
            $token = preg_match('/^Bearer (vk_api_[a-f0-9]{64})$/Di', $authorization, $match) ? $match[1] : '';
            $address = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
            if (!$this->limiter->take('integration', hash('sha256', $token), $address)) { return Wire::error(new ApiError(429, 'rate_limited', 'Zu viele Anfragen. Bitte später erneut versuchen.'))->withHeader('Retry-After', '60'); }
            $pdo = ($this->pdo)();
            $client = (new Clients($pdo))->authenticate($token, $protocol);
            $pdo->prepare('UPDATE integration_clients SET last_used_at=CURRENT_TIMESTAMP WHERE id=? AND revoked_at IS NULL')->execute([$client['id']]);
            if ($request->getMethod() === 'POST') {
                if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') { throw new ApiError(415, 'content_type', 'Content-Type application/json erforderlich.'); }
                $stream = $request->getBody();
                if ($stream->isSeekable()) { $stream->rewind(); }
                $raw = $stream->read(65537);
                if (strlen($raw) > 65536 || !$stream->eof()) { throw new ApiError(413, 'payload_too_large', 'Maximal 64 KiB pro Anfrage.'); }
                try { $decoded = json_decode($raw, false, 32, JSON_THROW_ON_ERROR); }
                catch (\JsonException) {
                    if ($protocol === 'mcp') { return Wire::json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']], 400); }
                    throw new ApiError(400, 'invalid_json', 'Ungültiges JSON.');
                }
                if (!$decoded instanceof \stdClass) {
                    if ($protocol === 'mcp') { return Wire::json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Invalid Request']], 400); }
                    throw new ApiError(400, 'invalid_json', 'Ein JSON-Objekt ist erforderlich.');
                }
                $request = $request->withBody((new StreamFactory())->createStream($raw))->withParsedBody((array) $decoded);
            }
            return $handler->handle($request->withAttribute('integration', $client)->withAttribute('integration_protocol', $protocol));
        } catch (ApiError $e) { return Wire::error($e); }
        catch (\Slim\Exception\HttpException $e) {
            return Wire::error(new ApiError($e->getCode(), 'http_error', 'Endpunkt oder HTTP-Methode nicht verfügbar.'));
        } catch (\Throwable $e) {
            // Do not echo database errors, submitted content or credentials to integration clients.
            error_log('Vereinskiste integration failure: ' . $e::class);
            return Wire::error(new ApiError(500, 'internal_error', 'Die Schnittstellenanfrage konnte nicht verarbeitet werden.'));
        }
    }
}
