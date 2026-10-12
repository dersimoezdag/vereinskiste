<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Ledger\AccountRepository;
use Vereinskiste\Ledger\DateInput;
use Vereinskiste\Ledger\InvalidAmount;
use Vereinskiste\Ledger\Money;

final class AccountController extends AdminController
{
    private const TYPES = ['cash', 'bank'];

    /** @param Closure():\PDO $pdo */
    public function __construct(private Closure $pdo)
    {
    }

    public function index(Request $req, Response $res): Response
    {
        return $this->render($req, $res);
    }

    public function create(Request $req, Response $res): Response
    {
        $body = $this->body($req);
        $input = [
            'name' => $this->str($body, 'name'),
            'type' => $this->str($body, 'type'),
            'opening_balance' => $this->str($body, 'opening_balance'),
            'opening_date' => $this->str($body, 'opening_date'),
        ];
        $repo = $this->repo();
        $errors = [];

        $error = $this->nameError($input['name']);
        if ($error === null && $repo->nameExists($input['name'])) {
            $error = 'validation.account.duplicate';
        }
        if ($error !== null) {
            $errors['name'] = $error;
        }
        if (!in_array($input['type'], self::TYPES, true)) {
            $errors['type'] = 'validation.account.type';
        }
        $balance = self::parseSignedAmount($input['opening_balance']);
        if ($balance === null) {
            $errors['opening_balance'] = 'validation.amount.invalid';
        }
        if (!DateInput::valid($input['opening_date'])) {
            $errors['opening_date'] = 'validation.date.invalid';
        }

        if ($errors !== []) {
            return $this->render($req, $res, 422, ['input' => $input, 'errors' => $errors]);
        }
        $repo->create($input['name'], $input['type'], (string) $balance, $input['opening_date']);
        $this->flash($req, 'accounts.flash.created');
        return $this->redirect('/accounts');
    }

    /** @param array{id:string} $args */
    public function rename(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $repo = $this->repo();
        if ($repo->find($id) === null) {
            return $this->notFound($req);
        }
        $name = $this->str($this->body($req), 'name');
        $error = $this->nameError($name);
        if ($error === null && $repo->nameExists($name, $id)) {
            $error = 'validation.account.duplicate';
        }
        if ($error !== null) {
            return $this->render($req, $res, 422, [
                'edit_id' => $id, 'edit_name' => $name, 'errors' => ['name' => $error],
            ]);
        }
        $repo->rename($id, $name);
        $this->flash($req, 'accounts.flash.renamed');
        return $this->redirect('/accounts');
    }

    /** @param array{id:string} $args */
    public function toggle(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $repo = $this->repo();
        if ($repo->find($id) === null) {
            return $this->notFound($req);
        }
        $repo->toggleActive($id);
        $this->flash($req, 'accounts.flash.toggled');
        return $this->redirect('/accounts');
    }

    /** @param array<string,mixed> $context */
    private function render(Request $req, Response $res, int $status = 200, array $context = []): Response
    {
        $context += [
            'input' => ['name' => '', 'type' => 'cash', 'opening_balance' => '0,00', 'opening_date' => date('Y-01-01')],
            'edit_id' => null, 'edit_name' => '', 'errors' => [],
        ];
        $repo = $this->repo();
        $accounts = $repo->all();
        foreach ($accounts as &$account) {
            $account['balance'] = $repo->balance($account['id']);
        }
        unset($account);
        $context['accounts'] = $accounts;
        return $req->getAttribute('view')->render($res->withStatus($status), 'admin/accounts.twig', $context);
    }

    private function repo(): AccountRepository
    {
        return new AccountRepository(($this->pdo)());
    }

    /** Anfangssaldo (optional negativ), Format siehe Money::parseSigned; null bei ungueltiger Eingabe. */
    private static function parseSignedAmount(string $input): ?string
    {
        try {
            return Money::parseSigned($input);
        } catch (InvalidAmount) {
            return null;
        }
    }
}
