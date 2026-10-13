<?php
declare(strict_types=1);
namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Dashboard\Catalog;
use Vereinskiste\Dashboard\Metrics;
use Vereinskiste\Dashboard\Preferences;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Ledger\ValidationError;

final class HomeDashboardController extends AdminController
{
    public function __construct(private Closure $pdo) {}

    public function index(Request $req, Response $res): Response
    {
        $data = $this->data($req);
        $metrics = new Metrics(($this->pdo)());
        $data['metrics'] = [];
        foreach ($data['preferences']['cards'] as $id) { $data['metrics'][$id] = $metrics->forCard($id); }
        return $req->getAttribute('view')->render($res, 'home.twig', $data);
    }

    public function preferences(Request $req, Response $res): Response
    {
        return $req->getAttribute('view')->render($res, 'dashboard/preferences.twig', $this->data($req));
    }

    public function save(Request $req, Response $res): Response
    {
        $data = $this->data($req);
        $body = $this->body($req);
        $status = 200;
        $error = null;
        try {
            if (!is_scalar($body['version'] ?? null) || !preg_match('/^(0|[1-9][0-9]{0,8})$/D', (string) $body['version'])) {
                throw new ValidationError(['dashboard' => 'foundation.invalid']);
            }
            $version = (new Preferences(($this->pdo)()))->save($this->userId($req), Fields::version($body['version'] ?? null), $body, $data['cards'], $data['shortcuts']);
        } catch (Conflict) {
            $status = 409;
            $error = 'Die Übersicht wurde in einem anderen Fenster geändert. Bitte neu laden, bevor du erneut speicherst.';
        } catch (ValidationError) {
            $status = 422;
            $error = 'Die Auswahl ist ungültig oder nicht mehr verfügbar. Bitte lade die Seite neu.';
        }
        if (str_contains($req->getHeaderLine('Accept'), 'application/json')) {
            $res->getBody()->write(json_encode($error === null ? ['version' => $version] : ['error' => $error], JSON_THROW_ON_ERROR));
            return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
        }
        if ($error !== null) {
            return $req->getAttribute('view')->render($res->withStatus($status), 'dashboard/preferences.twig', $data + ['error' => $error]);
        }
        return $this->redirect('/');
    }

    private function data(Request $req): array
    {
        $auth = $req->getAttribute('auth');
        $cards = Catalog::allowed(Catalog::CARDS, $auth->can(...));
        $shortcuts = Catalog::allowed(Catalog::SHORTCUTS, $auth->can(...));
        return ['cards' => $cards, 'shortcuts' => $shortcuts,
            'preferences' => (new Preferences(($this->pdo)()))->get($this->userId($req), $cards, $shortcuts)];
    }
}
