<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Vereinskiste\Auth\TooManyAttempts;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuthController
{
    public function showLogin(Request $req, Response $res): Response
    {
        if ($req->getAttribute('auth')->currentUser() !== null) {
            return $this->redirect($res, '/');
        }
        return $req->getAttribute('view')->render($res, 'auth/login.twig', ['error' => false, 'email' => '']);
    }

    public function login(Request $req, Response $res): Response
    {
        $body = (array) $req->getParsedBody();
        $email = is_string($body['email'] ?? null) ? $body['email'] : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';

        try {
            $address = $req->getServerParams()['REMOTE_ADDR'] ?? '';
            $user = $req->getAttribute('auth')->login($email, $password, is_string($address) ? $address : '');
        } catch (TooManyAttempts) {
            return $req->getAttribute('view')->render($res->withStatus(429)->withHeader('Retry-After', '60'),
                'auth/login.twig', ['error' => false, 'throttled' => true, 'email' => '']);
        }
        if ($user === null) {
            return $req->getAttribute('view')
                ->render($res->withStatus(401), 'auth/login.twig', ['error' => true, 'email' => mb_check_encoding($email, 'UTF-8') ? trim($email) : '']);
        }
        return $this->redirect($res, '/');
    }

    public function logout(Request $req, Response $res): Response
    {
        $req->getAttribute('auth')->logout();
        return $this->redirect($res, '/login');
    }

    private function redirect(Response $res, string $to): Response
    {
        return $res->withStatus(302)->withHeader('Location', $to);
    }
}
