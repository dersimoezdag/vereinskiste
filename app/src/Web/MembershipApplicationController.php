<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Vereinskiste\Http\Session\SessionStore;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\ApplicationRepository;

final class MembershipApplicationController
{
    public function __construct(private Closure $pdo, private ?\Vereinskiste\Auth\RateLimiter $limiter = null) {}

    public function show(Request $req, Response $res, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        $appRepo = new ApplicationRepository(($this->pdo)());
        $invite = $appRepo->findInviteByToken($token);

        if ($invite === null) {
            return $req->getAttribute('view')->render($res->withStatus(404), 'public/membership_application_invalid.twig', [
                'reason' => 'invalid',
            ]);
        }

        if ($invite['is_expired']) {
            return $req->getAttribute('view')->render($res->withStatus(410), 'public/membership_application_invalid.twig', [
                'reason' => 'expired',
                'invite' => $invite,
            ]);
        }

        if ($invite['is_used']) {
            return $req->getAttribute('view')->render($res->withStatus(410), 'public/membership_application_invalid.twig', [
                'reason' => 'used',
                'invite' => $invite,
            ]);
        }

        if ($invite['has_password'] && !$this->isUnlocked($req, $token)) {
            return $req->getAttribute('view')->render($res, 'public/membership_application_password.twig', [
                'invite' => $invite,
                'token' => $token,
                'error' => null,
            ]);
        }

        return $this->renderForm($req, $res, $invite);
    }

    public function unlock(Request $req, Response $res, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        $appRepo = new ApplicationRepository(($this->pdo)());
        $invite = $appRepo->findInviteByToken($token);

        if ($invite === null || !$invite['is_valid']) {
            return (new SlimResponse())->withStatus(302)->withHeader('Location', '/antrag/' . $token);
        }

        if (!$this->budget($req, 'application_unlock', $token)) { return $res->withStatus(429)->withHeader('Retry-After', '60'); }
        $body = (array) $req->getParsedBody();
        $password = (string) ($body['password'] ?? '');

        if ($appRepo->verifyInvitePassword($token, $password)) {
            $this->setUnlocked($req, $token);
            return (new SlimResponse())->withStatus(302)->withHeader('Location', '/antrag/' . $token);
        }

        return $req->getAttribute('view')->render($res->withStatus(422), 'public/membership_application_password.twig', [
            'invite' => $invite,
            'token' => $token,
            'error' => 'applications.password_prompt.invalid',
        ]);
    }

    public function submit(Request $req, Response $res, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        $appRepo = new ApplicationRepository(($this->pdo)());
        $invite = $appRepo->findInviteByToken($token);

        if ($invite === null || !$invite['is_valid']) {
            return (new SlimResponse())->withStatus(302)->withHeader('Location', '/antrag/' . $token);
        }

        if ($invite['has_password'] && !$this->isUnlocked($req, $token)) {
            return (new SlimResponse())->withStatus(302)->withHeader('Location', '/antrag/' . $token);
        }

        if (!$this->budget($req, 'application', $token)) { return $res->withStatus(429)->withHeader('Retry-After', '60'); }
        $body = (array) $req->getParsedBody();

        // Honeypot check: Bots fuellen oft versteckte Felder aus
        if (!empty($body['website'])) {
            return (new SlimResponse())->withStatus(302)->withHeader('Location', '/antrag/danke');
        }

        try {
            $appRepo->submit($token, $body);
        } catch (ValidationError $e) {
            return $this->renderForm($req, $res->withStatus(422), $invite, $body, $e->errors);
        }

        return (new SlimResponse())->withStatus(302)->withHeader('Location', '/antrag/danke');
    }

    public function thankYou(Request $req, Response $res): Response
    {
        return $req->getAttribute('view')->render($res, 'public/membership_application_success.twig');
    }

    private function renderForm(Request $req, Response $res, array $invite, ?array $input = null, array $errors = []): Response
    {
        $safe = [];
        $defaults = [
            'country' => 'Deutschland',
            'consent_statute' => '',
            'consent_privacy' => '',
            'sepa_mandate_consent' => '',
        ];
        foreach (($input ?? $defaults) as $key => $value) {
            $safe[$key] = (is_string($value) || is_int($value)) && mb_check_encoding((string) $value, 'UTF-8') ? (string) $value : '';
        }

        return $req->getAttribute('view')->render($res, 'public/membership_application.twig', [
            'invite' => $invite,
            'token' => $invite['token'],
            'input' => $safe,
            'errors' => $errors,
            'document_fingerprint' => (new \Vereinskiste\Privacy\Consents(($this->pdo)()))->fingerprint(),
            'consent_documents' => ['statute' => (new \Vereinskiste\Privacy\Consents(($this->pdo)()))->snapshot('statute'), 'privacy' => (new \Vereinskiste\Privacy\Consents(($this->pdo)()))->snapshot('privacy')],
        ]);
    }

    private function budget(Request $req, string $operation, string $token): bool
    {
        $address = $req->getServerParams()['REMOTE_ADDR'] ?? '';
        return $this->limiter === null || $this->limiter->take($operation, $token, is_string($address) ? $address : '');
    }

    private function isUnlocked(Request $req, string $token): bool
    {
        /** @var SessionStore|null $session */
        $session = $req->getAttribute('session');
        if ($session === null) {
            return false;
        }
        $unlocked = (array) $session->get('unlocked_invites', []);
        return !empty($unlocked[$token]);
    }

    private function setUnlocked(Request $req, string $token): void
    {
        /** @var SessionStore|null $session */
        $session = $req->getAttribute('session');
        if ($session === null) {
            return;
        }
        $unlocked = (array) $session->get('unlocked_invites', []);
        $unlocked[$token] = true;
        $session->set('unlocked_invites', $unlocked);
    }
}
