<?php
declare(strict_types=1);
namespace Vereinskiste\Integrations;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Web\AdminController;

final class SettingsController extends AdminController
{
    public function __construct(private Closure $pdo, private string $baseUrl = '') {}

    public function index(Request $req, Response $res): Response
    {
        $session = $req->getAttribute('session');
        $secret = $session->get('integration_new_token');
        $session->remove('integration_new_token');
        if (!is_array($secret) || ($secret['owner'] ?? null) !== $this->userId($req)) { $secret = null; }
        return $this->page($req, $res, [], [], $secret);
    }

    public function create(Request $req, Response $res): Response
    {
        $input = $this->body($req);
        try { $secret = (new Clients(($this->pdo)()))->create($input, $this->userId($req)); }
        catch (ValidationError $e) { return $this->page($req, $res->withStatus(422), $input, $e->errors); }
        $req->getAttribute('session')->set('integration_new_token', $secret + ['owner' => $this->userId($req)]);
        return $this->redirect('/settings/integrations');
    }

    public function revoke(Request $req, Response $res, array $args): Response
    {
        if (!(new Clients(($this->pdo)()))->revoke((int) $args['id'], $this->userId($req))) { return $this->notFound($req); }
        $this->flash($req, 'integrations.revoked_success');
        return $this->redirect('/settings/integrations');
    }

    public function connection(Request $req, Response $res): Response
    {
        $origin = $this->baseUrl !== '' ? rtrim($this->baseUrl, '/') : $req->getUri()->getScheme() . '://' . $req->getUri()->getAuthority();
        return $req->getAttribute('view')->render($res, 'settings/integration_connection.twig', ['origin' => $origin, 'operations' => Catalog::operations()]);
    }

    public function activity(Request $req, Response $res): Response
    {
        $rows = ($this->pdo)()->query("SELECT a.at,c.name,a.detail->>'operation' AS operation,a.detail->>'protocol' AS protocol,a.detail->>'status' AS status FROM audit_log a JOIN integration_clients c ON c.id::text=a.detail->>'client_id' WHERE a.action='integration_access' ORDER BY a.at DESC,a.id DESC LIMIT 100")->fetchAll();
        return $req->getAttribute('view')->render($res, 'settings/integration_activity.twig', ['activity' => $rows]);
    }

    private function page(Request $req, Response $res, array $input = [], array $errors = [], ?array $secret = null): Response
    {
        // Keep malformed checkbox values out of Twig's string and membership operations.
        $safe = ['name' => $this->str($input, 'name'), 'valid_days' => $this->str($input, 'valid_days') ?: '90'];
        foreach (['scopes', 'protocols', 'allowed_roles'] as $field) { $safe[$field] = is_array($input[$field] ?? null) ? array_values(array_filter($input[$field], 'is_string')) : []; }
        if (!$input) { $safe['protocols'] = ['api']; }
        return $req->getAttribute('view')->render($res->withHeader('Cache-Control', 'no-store'), 'settings/integrations.twig', [
            'clients' => (new Clients(($this->pdo)()))->all(), 'scopes' => Catalog::SCOPES, 'roles' => Catalog::ROLES,
            'input' => $safe, 'errors' => $errors, 'secret' => $secret,
        ]);
    }
}
