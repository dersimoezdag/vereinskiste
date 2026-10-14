<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Mail\MailAccounts;
use Vereinskiste\Mail\Mailer;

final class MailSettingsController extends AdminController
{
    /** @param \Closure(array):Mailer|null $mailerFactory Testnaht für den Versand der Testmail */
    public function __construct(private MailAccounts $accounts, private ?\Closure $mailerFactory = null) {}

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

    /** Sendet eine Testmail mit den aktuellen Formularwerten, ohne zu speichern. */
    public function test(Request $req, Response $res): Response
    {
        $input = $this->body($req);
        $to = $this->str($input, 'test_to');
        if ($to === '') { $to = $this->str($input, 'from'); }
        $config = [];
        foreach (array_keys(MailAccounts::FIELDS) as $key) { $config[$key] = $this->str($input, $key); }
        $password = $input['password'] ?? '';
        $config['password'] = is_string($password) && $password !== '' ? $password
            : (($input['clear_password'] ?? '') === '1' ? '' : ($this->accounts->get()['config']['password'] ?? ''));
        $mailer = $this->mailerFactory !== null ? ($this->mailerFactory)($config) : new Mailer($config);
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $result = ['ok' => false, 'message' => 'mail.test.invalid_recipient'];
        } elseif (!$mailer->isConfigured()) {
            $result = ['ok' => false, 'message' => 'mail.test.not_configured'];
        } else {
            try {
                $mailer->send($to, 'Vereinskiste: Test-E-Mail', "Diese Test-E-Mail bestätigt, dass der E-Mail-Versand der Vereinskiste funktioniert.\n");
                $result = ['ok' => true, 'message' => 'mail.test.sent'];
            } catch (\Throwable $e) {
                $result = ['ok' => false, 'message' => 'mail.test.failed', 'detail' => $e->getMessage()];
            }
        }
        return $this->render($req, $res, $input, [], $result + ['to' => $to]);
    }

    private function render(Request $req, Response $res, ?array $input = null, array $errors = [], ?array $testResult = null): Response
    {
        $state = $this->accounts->get();
        $defaults = $state['config'] + ['host' => '', 'port' => '587', 'from' => '', 'from_name' => '',
            'username' => '', 'encryption' => 'tls', 'enabled' => false];
        $defaults['enabled'] = $defaults['enabled'] ? '1' : '0';
        $safe = [];
        foreach (($input ?? $defaults) as $key => $value) {
            if (array_key_exists($key, MailAccounts::FIELDS) || $key === 'enabled' || $key === 'test_to') {
                $safe[$key] = (is_string($value) || is_int($value)) && mb_check_encoding((string) $value, 'UTF-8') ? (string) $value : '';
            }
        }
        return $req->getAttribute('view')->render($res, 'settings/email.twig', [
            'input' => $safe, 'errors' => $errors,
            'version' => $input === null ? $state['version'] : $this->str($input, 'version'),
            'has_password' => ($state['config']['password'] ?? '') !== '',
            'test_result' => $testResult,
        ]);
    }
}
