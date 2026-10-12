<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Auth\PasswordResetService;
use Vereinskiste\Ledger\ValidationError;

final class PasswordResetController extends AdminController
{
    public function __construct(private PasswordResetService $service)
    {
    }

    public function showRequest(Request $req, Response $res): Response
    {
        return $this->render($req, $res, 'auth/reset_request.twig');
    }

    public function request(Request $req, Response $res): Response
    {
        $address = $req->getServerParams()['REMOTE_ADDR'] ?? '';
        $this->service->request($this->str($this->body($req), 'email'), is_string($address) ? $address : '');
        return $this->render($req, $res, 'auth/reset_request.twig', ['sent' => true]);
    }

    public function showConfirm(Request $req, Response $res, array $args): Response
    {
        return $this->render($req, $res, 'auth/reset_confirm.twig', [
            'token' => $args['token'], 'valid' => $this->service->isValid($args['token']),
        ]);
    }

    public function confirm(Request $req, Response $res, array $args): Response
    {
        $body = $this->body($req);
        $errors = [];
        try {
            $done = $this->service->reset($args['token'],
                is_string($body['password'] ?? null) ? $body['password'] : '',
                is_string($body['confirmation'] ?? null) ? $body['confirmation'] : '');
        } catch (ValidationError $e) {
            $errors = $e->errors;
            $done = false;
        }
        if ($done) {
            $req->getAttribute('auth')->logout();
            return $this->render($req, $res, 'auth/reset_confirm.twig', ['done' => true]);
        }
        return $this->render($req, $res->withStatus(422), 'auth/reset_confirm.twig', [
            'token' => $args['token'], 'valid' => $this->service->isValid($args['token']), 'errors' => $errors,
        ]);
    }

    private function render(Request $req, Response $res, string $template, array $context = []): Response
    {
        return $req->getAttribute('view')->render($res
            ->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer'), $template,
            $context + ['configured' => $this->service->isConfigured(), 'sent' => false,
                'done' => false, 'valid' => false, 'token' => '', 'errors' => []]);
    }
}
