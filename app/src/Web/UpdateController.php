<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;
use Vereinskiste\Install\Updater;
use Vereinskiste\Install\GitHubReleases;

final class UpdateController extends AdminController
{
    public function __construct(private Closure $pdo, private string $migrationDir, private string $version,
        private GitHubReleases $releases)
    {
    }

    public function show(Request $req, Response $res): Response
    {
        return $this->render($req, $res);
    }

    public function check(Request $req, Response $res): Response
    {
        $req->getAttribute('session')->set('github_release', $this->releases->check($this->version));
        $req->getAttribute('session')->set('github_release_installed', $this->version);
        return $this->redirect('/settings');
    }

    public function update(Request $req, Response $res): Response
    {
        if (($this->body($req)['backup_confirmed'] ?? '') !== '1') {
            return $this->render($req, $res->withStatus(422), 'update.backup_required');
        }
        try {
            (new Updater(($this->pdo)(), $this->migrationDir))->run(true);
        } catch (Throwable) {
            return $this->render($req, $res->withStatus(422), 'update.failed');
        }
        return $this->render($req, $res, null, true);
    }

    private function render(Request $req, Response $res, ?string $error = null, bool $done = false): Response
    {
        $updater = new Updater(($this->pdo)(), $this->migrationDir);
        return $req->getAttribute('view')->render($res->withHeader('Cache-Control', 'no-store'),
            'install/update.twig', ['pending' => $updater->needsUpdate(), 'compatible' => $updater->isCompatible(),
                'error' => $error, 'done' => $done, 'version' => $this->version,
                'release' => $req->getAttribute('session')->get('github_release_installed') === $this->version
                    ? ($req->getAttribute('session')->get('github_release') ?? ['status' => 'unchecked'])
                    : ['status' => 'unchecked']]);
    }
}
