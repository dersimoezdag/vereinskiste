<?php
declare(strict_types=1);
namespace Vereinskiste\Integrations;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class RestController
{
    public function __construct(private Closure $pdo) {}

    public function openapi(Request $req, Response $res): Response
    {
        return Wire::json(OpenApi::document($req->getAttribute('integration')['scopes']));
    }

    public function capabilities(Request $req, Response $res): Response
    {
        $client = $req->getAttribute('integration');
        return Wire::json(['name' => $client['name'], 'scopes' => $client['scopes'], 'allowed_roles' => $client['allowed_roles'], 'tools' => Catalog::tools($client['scopes'])]);
    }

    public function operation(string $name): Closure
    {
        return function (Request $req, Response $res, array $args) use ($name): Response {
            try {
                $read = $req->getMethod() === 'GET';
                $input = $read ? $req->getQueryParams() : Wire::body($req);
                if (isset($args['id'])) { $input['id'] = (int) $args['id']; }
                $result = (new Dispatcher(($this->pdo)()))->call($req->getAttribute('integration'), 'api', $name, $input, $read);
                return Wire::json($result, $read ? 200 : 201);
            } catch (ApiError $e) { return Wire::error($e); }
        };
    }
}
