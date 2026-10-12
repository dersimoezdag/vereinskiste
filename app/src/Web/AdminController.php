<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Vereinskiste\Http\ErrorPage;
use Vereinskiste\Http\Session\SessionStore;
use Vereinskiste\Ledger\ClosedPeriod;

/** Gemeinsame Helfer der Stammdaten-Seiten (Konten, Kategorien). */
abstract class AdminController
{
    protected const NAME_MAX = 100;

    /** @return array<string,mixed> */
    protected function body(Request $req): array
    {
        return (array) $req->getParsedBody();
    }

    /** @param array<string,mixed> $body */
    protected function str(array $body, string $key): string
    {
        return is_string($body[$key] ?? null) ? trim($body[$key]) : '';
    }

    /** @return string|null Textschluessel des Fehlers oder null, wenn der Name formal passt */
    protected function nameError(string $name): ?string
    {
        if ($name === '') {
            return 'validation.name.required';
        }
        if (!mb_check_encoding($name, 'UTF-8')) {
            return 'validation.text.encoding';
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            return 'validation.name.too_long';
        }
        return null;
    }

    protected function userId(Request $req): int
    {
        return $req->getAttribute('auth')->currentUser()['id'];
    }

    protected function flash(Request $req, string $textKey): void
    {
        /** @var SessionStore $session */
        $session = $req->getAttribute('session');
        $session->set('flash', $textKey);
    }

    protected function redirect(string $to): Response
    {
        return (new SlimResponse())->withStatus(302)->withHeader('Location', $to);
    }

    /** Buchung liegt im abgeschlossenen Zeitraum: 409 mit Datum und Hinweis auf die Korrekturbuchung. */
    protected function closedPeriod(Request $req, ClosedPeriod $e, string $backUrl = '/bookings'): Response
    {
        return ErrorPage::render(
            $req->getAttribute('view'),
            409,
            'closing.rejected.title',
            'closing.rejected.text',
            $backUrl,
            'common.back',
            ['{date}' => date('d.m.Y', (int) strtotime($e->closedUntil))]
        );
    }

    protected function notFound(Request $req): Response
    {
        return ErrorPage::render($req->getAttribute('view'), 404, 'error.404.title', 'error.404.text');
    }
}
