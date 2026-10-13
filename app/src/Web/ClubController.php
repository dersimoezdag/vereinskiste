<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Club\Profile;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Foundation\History;
use Vereinskiste\Ledger\ValidationError;

final class ClubController extends AdminController
{
    public function __construct(private Closure $pdo) {}

    public function show(Request $req, Response $res): Response { return $this->render($req, $res); }

    public function save(Request $req, Response $res): Response
    {
        $body = $this->body($req);
        try {
            (new Profile(($this->pdo)()))->save($body, Fields::version($body['version'] ?? null),
                $this->userId($req), $this->str($body, 'reason'));
        } catch (ValidationError $e) {
            return $this->render($req, $res->withStatus(422), $body, $e->errors);
        } catch (Conflict) {
            return $this->render($req, $res->withStatus(409), $body, ['version' => 'foundation.conflict']);
        }
        $this->flash($req, 'foundation.saved');
        return $this->redirect('/settings/club');
    }

    private function render(Request $req, Response $res, ?array $input = null, array $errors = []): Response
    {
        $pdo = ($this->pdo)();
        $profile = (new Profile($pdo))->get();
        $safe = [];
        foreach (array_keys(Profile::FIELDS) as $key) {
            $value = $input[$key] ?? $profile['data'][$key];
            $safe[$key] = is_string($value) && mb_check_encoding($value, 'UTF-8') ? $value : '';
        }
        return $req->getAttribute('view')->render($res, 'settings/club.twig', [
            'input' => $safe, 'version' => $input === null ? $profile['version'] : Fields::version($input['version'] ?? null),
            'reason' => is_string($input['reason'] ?? null) && mb_check_encoding($input['reason'], 'UTF-8') ? $input['reason'] : '',
            'errors' => $errors, 'fields' => Profile::FIELDS, 'logo_id' => $profile['logo_id'], 'history' => (new History($pdo))->for('club', 1),
            'jobs' => (new \Vereinskiste\Jobs\Queue($pdo))->summary(),
        ]);
    }
}
