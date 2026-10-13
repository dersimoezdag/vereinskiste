<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Finance\AccountBank;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Foundation\History;
use Vereinskiste\Ledger\ValidationError;

final class AccountBankController extends AdminController
{
    public function __construct(private Closure $pdo) {}

    public function show(Request $req, Response $res, array $args): Response
    {
        return $this->render($req, $res, (int) $args['id']);
    }

    public function save(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id']; $body = $this->body($req);
        if ((new AccountBank(($this->pdo)()))->get($id) === null) { return $this->notFound($req); }
        try {
            (new AccountBank(($this->pdo)()))->save($id, $this->str($body, 'iban'), $this->str($body, 'bic'),
                Fields::version($body['version'] ?? null), $this->userId($req), $this->str($body, 'reason'));
        } catch (ValidationError $e) {
            return $this->render($req, $res->withStatus(422), $id, $body, $e->errors);
        } catch (Conflict) {
            return $this->render($req, $res->withStatus(409), $id, $body, ['version' => 'foundation.conflict']);
        }
        $this->flash($req, 'foundation.saved');
        return $this->redirect('/accounts/' . $id . '/bank');
    }

    private function render(Request $req, Response $res, int $id, ?array $body = null, array $errors = []): Response
    {
        $pdo = ($this->pdo)(); $account = (new AccountBank($pdo))->get($id);
        if ($account === null) { return $this->notFound($req); }
        $input = [];
        foreach (['iban', 'bic', 'reason'] as $key) {
            $value = $body[$key] ?? $account[$key] ?? '';
            $input[$key] = is_string($value) && mb_check_encoding($value, 'UTF-8') ? $value : '';
        }
        return $req->getAttribute('view')->render($res, 'settings/account_bank.twig', [
            'account' => $account, 'input' => $input, 'fields' => ['iban' => 50, 'bic' => 11],
            'version' => $body === null ? $account['bank_version'] : Fields::version($body['version'] ?? null),
            'errors' => $errors, 'history' => (new History($pdo))->for('account_bank', $id),
        ]);
    }
}
