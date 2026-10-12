<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;
use Vereinskiste\Install\Installer;
use Vereinskiste\Install\SystemCheck;

final class InstallController extends AdminController
{
    public function __construct(private string $appDir)
    {
    }

    public function show(Request $req, Response $res): Response
    {
        return $this->render($req, $res);
    }

    public function install(Request $req, Response $res): Response
    {
        $body = $this->body($req);
        $input = [];
        foreach (['db_host', 'db_port', 'db_name', 'db_user', 'club_name', 'base_url', 'admin_name',
            'admin_email', 'smtp_host', 'smtp_port', 'smtp_from', 'smtp_username', 'smtp_encryption'] as $field) {
            $input[$field] = $this->str($body, $field);
        }
        $raw = static fn (string $key): string => is_string($body[$key] ?? null) ? $body[$key] : '';
        $smtp = $input['smtp_host'] === '' ? null : [
            'host' => $input['smtp_host'], 'port' => $input['smtp_port'], 'from' => $input['smtp_from'],
            'username' => $input['smtp_username'], 'password' => $raw('smtp_password'),
            'encryption' => $input['smtp_encryption'],
        ];
        try {
            (new Installer($this->appDir))->install([
                'host' => $input['db_host'], 'port' => $input['db_port'], 'name' => $input['db_name'],
                'user' => $input['db_user'], 'pass' => $raw('db_password'),
            ], ['name' => $input['club_name'], 'base_url' => $input['base_url']], [
                'name' => $input['admin_name'], 'email' => $input['admin_email'], 'password' => $raw('admin_password'),
            ], $smtp);
        } catch (Throwable) {
            // DB/SMTP-Fehler koennten Zugangsdaten enthalten; weder anzeigen noch loggen.
            return $this->render($req, $res->withStatus(422), $input, true);
        }
        $req->getAttribute('session')->destroy();
        return $this->redirect('/login');
    }

    private function render(Request $req, Response $res, array $input = [], bool $error = false): Response
    {
        $installed = (new Installer($this->appDir))->isInstalled();
        return $req->getAttribute('view')->render($res->withStatus($installed ? 403 : $res->getStatusCode())
            ->withHeader('Cache-Control', 'no-store'), 'install/setup.twig', [
                'checks' => (new SystemCheck($this->appDir))->run(), 'installed' => $installed, 'error' => $error,
                'input' => $input + ['db_host' => 'localhost', 'db_port' => '5432', 'smtp_port' => '587',
                    'smtp_encryption' => 'tls'],
            ]);
    }
}
