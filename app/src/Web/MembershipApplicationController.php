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

        if ($invite['is_revoked']) {
            return $req->getAttribute('view')->render($res->withStatus(410), 'public/membership_application_invalid.twig', [
                'reason' => 'revoked',
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

        $session = $req->getAttribute('session');
        $session?->remove('application_pdf');

        // Honeypot check: Bots fuellen oft versteckte Felder aus
        if (!empty($body['website'])) {
            return (new SlimResponse())->withStatus(302)->withHeader('Location', '/antrag/danke');
        }

        try {
            $applicationId = $appRepo->submit($token, $body);
        } catch (ValidationError $e) {
            return $this->renderForm($req, $res->withStatus(422), $invite, $body, $e->errors);
        }

        if ($applicationId > 0) {
            $session?->set('application_pdf', ['id' => $applicationId, 'expires' => time() + 3600]);
        }
        return (new SlimResponse())->withStatus(302)->withHeader('Location', '/antrag/danke' . ($invite['is_test'] ? '?test=1' : ''));
    }

    public function thankYou(Request $req, Response $res): Response
    {
        return $req->getAttribute('view')->render($res->withHeader('Cache-Control', 'no-store'), 'public/membership_application_success.twig', [
            'is_test' => ($req->getQueryParams()['test'] ?? '') === '1',
            'pdf_available' => $this->submittedApplication($req) !== null,
        ]);
    }

    public function pdf(Request $req, Response $res): Response
    {
        $application = $this->submittedApplication($req);
        if ($application === null) {
            return $res->withStatus(404)->withHeader('Cache-Control', 'no-store');
        }
        return ApplicationPdfDownload::respond(($this->pdo)(), $res, $application, null);
    }

    private function submittedApplication(Request $req): ?array
    {
        $grant = $req->getAttribute('session')?->get('application_pdf');
        if (!is_array($grant) || ($grant['expires'] ?? 0) <= time() || !is_int($grant['id'] ?? null)) {
            return null;
        }
        $application = (new ApplicationRepository(($this->pdo)()))->findApplication($grant['id']);
        return $application && empty($application['anonymized_at']) ? $application : null;
    }

    private function renderForm(Request $req, Response $res, array $invite, ?array $input = null, array $errors = []): Response
    {
        $safe = [];
        $defaults = [
            'country' => 'Deutschland',
            'sepa_mandate_consent' => '',
        ];
        foreach (($input ?? $defaults) as $key => $value) {
            $safe[$key] = (is_string($value) || is_int($value)) && mb_check_encoding((string) $value, 'UTF-8') ? (string) $value : '';
        }

        $pdo = ($this->pdo)();
        $club = (string) ((new \Vereinskiste\Club\Profile($pdo))->get()['data']['name'] ?? '');
        $declarations = array_map(static fn(array $d): array => ['title' => \Vereinskiste\Members\Declarations::render($d['title'], $club, false),
            'content' => \Vereinskiste\Members\Declarations::render($d['content'], $club, true)] + $d,
            (new \Vereinskiste\Members\Declarations($pdo))->active());
        $strings = static fn(mixed $values): array => is_array($values) ? array_map(
            static fn(mixed $v): string => is_string($v) && mb_check_encoding($v, 'UTF-8') ? $v : '',
            array_filter($values, static fn(mixed $k): bool => is_int($k) || ctype_digit((string) $k), ARRAY_FILTER_USE_KEY)) : [];

        return $req->getAttribute('view')->render($res, 'public/membership_application.twig', [
            'invite' => $invite,
            'token' => $invite['token'],
            'input' => $safe,
            'errors' => $errors,
            'document_fingerprint' => (new \Vereinskiste\Privacy\Consents(($this->pdo)()))->fingerprint(),
            'declarations' => $declarations,
            'answers' => $strings($input['declaration'] ?? null),
            'signatures' => $strings($input['signature'] ?? null),
            'places' => $strings($input['signed_place'] ?? null),
            'today' => (new \DateTimeImmutable('today'))->format('d.m.Y'),
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
