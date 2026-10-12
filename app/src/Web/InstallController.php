<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;
use Vereinskiste\Install\Installer;
use Vereinskiste\Install\SystemCheck;
use Vereinskiste\Install\SetupKey;
use Vereinskiste\Auth\RateLimiter;

final class InstallController extends AdminController
{
    public function __construct(private string $appDir, private RateLimiter $limiter)
    {
    }

    public function show(Request $req, Response $res): Response
    {
        return $this->render($req, $res);
    }

    public function install(Request $req, Response $res): Response
    {
        if (!$this->authorized($req)) {
            return $this->render($req, $res->withStatus(403));
        }
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
            ], $smtp, (string) $req->getAttribute('session')->get('setup_proof'));
        } catch (Throwable) {
            // DB/SMTP-Fehler koennten Zugangsdaten enthalten; weder anzeigen noch loggen.
            return $this->render($req, $res->withStatus(422), $input, true);
        }
        $req->getAttribute('session')->destroy();
        return $this->redirect('/login');
    }

    public function unlock(Request $req, Response $res): Response
    {
        $address = $req->getServerParams()['REMOTE_ADDR'] ?? '';
        if (!$this->limiter->take('setup', '*', is_string($address) ? $address : '')) {
            return $this->render($req, $res->withStatus(429), [], true);
        }
        $proof = (new SetupKey($this->appDir))->authorize($this->str($this->body($req), 'setup_key'));
        if ($proof === null) {
            return $this->render($req, $res->withStatus(403), [], true);
        }
        $session = $req->getAttribute('session');
        $session->regenerate();
        $session->set('setup_proof', $proof);
        return $this->redirect('/install');
    }

    private function authorized(Request $req): bool
    {
        $proof = $req->getAttribute('session')->get('setup_proof');
        return is_string($proof) && (new SetupKey($this->appDir))->accepts($proof);
    }

    private function render(Request $req, Response $res, array $input = [], bool $error = false): Response
    {
        $installed = (new Installer($this->appDir))->isInstalled();
        return $req->getAttribute('view')->render($res->withStatus($installed ? 403 : $res->getStatusCode())
            ->withHeader('Cache-Control', 'no-store'), 'install/setup.twig', [
                'checks' => $this->authorized($req) ? (new SystemCheck($this->appDir))->run() : [],
                'authorized' => $this->authorized($req), 'installed' => $installed, 'error' => $error,
                'input' => $input + ['db_host' => 'localhost', 'db_port' => '5432', 'smtp_port' => '587',
                    'smtp_encryption' => 'tls'],
            ]);
    }
}
