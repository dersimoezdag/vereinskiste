<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

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

        $user = $req->getAttribute('auth')->login($email, $password);
        if ($user === null) {
            return $req->getAttribute('view')
                ->render($res->withStatus(401), 'auth/login.twig', ['error' => true, 'email' => trim($email)]);
        }
        return $this->redirect($res, '/');
    }

    public function logout(Request $req, Response $res): Response
    {
        $req->getAttribute('auth')->logout();
        return $this->redirect($res, '/login');
    }

    public function home(Request $req, Response $res): Response
    {
        return $req->getAttribute('view')->render($res, 'home.twig');
    }

    private function redirect(Response $res, string $to): Response
    {
        return $res->withStatus(302)->withHeader('Location', $to);
    }
}
