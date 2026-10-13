<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Mail\MailAccounts;

final class MailSettingsController extends AdminController
{
    public function __construct(private MailAccounts $accounts) {}

    public function show(Request $req, Response $res): Response { return $this->render($req, $res); }

    public function save(Request $req, Response $res): Response
    {
        $input = $this->body($req);
        try {
            // The initial configuration has version zero.
            $this->accounts->saveSystem($input, Fields::version($input['version'] ?? null));
        } catch (ValidationError $e) {
            return $this->render($req, $res->withStatus(422), $input, $e->errors);
        } catch (Conflict) {
            return $this->render($req, $res->withStatus(409), $input, ['version' => 'foundation.conflict']);
        }
        $this->flash($req, 'foundation.saved');
        return $this->redirect('/settings/email');
    }

    private function render(Request $req, Response $res, ?array $input = null, array $errors = []): Response
    {
        $state = $this->accounts->get();
        $defaults = $state['config'] + ['host' => '', 'port' => '587', 'from' => '', 'from_name' => '',
            'username' => '', 'encryption' => 'tls', 'enabled' => false];
        $defaults['enabled'] = $defaults['enabled'] ? '1' : '0';
        $safe = [];
        foreach (($input ?? $defaults) as $key => $value) {
            if (array_key_exists($key, MailAccounts::FIELDS) || $key === 'enabled') {
                $safe[$key] = (is_string($value) || is_int($value)) && mb_check_encoding((string) $value, 'UTF-8') ? (string) $value : '';
            }
        }
        return $req->getAttribute('view')->render($res, 'settings/email.twig', [
            'input' => $safe, 'errors' => $errors,
            'version' => $input === null ? $state['version'] : $this->str($input, 'version'),
            'has_password' => ($state['config']['password'] ?? '') !== '',
        ]);
    }
}
