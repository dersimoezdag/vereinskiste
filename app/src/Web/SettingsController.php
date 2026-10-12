<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Audit\AuditLog;
use Vereinskiste\Ledger\ClosingService;
use Vereinskiste\Ledger\ValidationError;

/** Abschluss ("abgeschlossen bis") und Protokoll; beides nur mit `settings.manage` (Vorstand). */
final class SettingsController extends AdminController
{
    public const AUDIT_PAGE_SIZE = 50;

    /** @param Closure():\PDO $pdo */
    public function __construct(private Closure $pdo)
    {
    }

    public function closing(Request $req, Response $res): Response
    {
        return $this->renderClosing($req, $res);
    }

    public function saveClosing(Request $req, Response $res): Response
    {
        $body = $this->body($req);
        $action = $this->str($body, 'action');
        $date = $this->str($body, 'closed_until');
        $reason = $this->str($body, 'reason');
        $service = new ClosingService(($this->pdo)());
        $userId = $req->getAttribute('auth')->currentUser()['id'];

        try {
            if ($action === 'close') {
                $service->close($date, $userId);
                $this->flash($req, 'closing.flash.closed');
            } elseif ($action === 'reopen') {
                $service->reopen($date, $reason, $userId);
                $this->flash($req, 'closing.flash.reopened');
            } else {
                throw new ValidationError(['action' => 'validation.closing.action']);
            }
        } catch (ValidationError $e) {
            return $this->renderClosing($req, $res->withStatus(422), $action, $date, $reason, $e->errors);
        }
        return $this->redirect('/settings/closing');
    }

    public function auditLog(Request $req, Response $res): Response
    {
        $log = new AuditLog(($this->pdo)());
        $query = $req->getQueryParams();
        $action = AuditLog::normalizeAction($query['action'] ?? null);

        $total = $log->count($action);
        $pages = max(1, (int) ceil($total / self::AUDIT_PAGE_SIZE));
        $rawPage = $query['page'] ?? '1';
        $page = is_string($rawPage) && preg_match('/^\d{1,9}\z/', $rawPage) === 1 ? (int) $rawPage : 1;
        $page = min(max(1, $page), $pages);

        $base = $action === null ? '' : 'action=' . rawurlencode($action) . '&';
        $link = static fn (int $n): string => '/audit-log?' . $base . 'page=' . $n;

        return $req->getAttribute('view')->render($res, 'admin/audit.twig', [
            'entries' => $log->page($action, self::AUDIT_PAGE_SIZE, ($page - 1) * self::AUDIT_PAGE_SIZE),
            'actions' => AuditLog::ACTIONS,
            'action' => $action,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'prev_url' => $page > 1 ? $link($page - 1) : null,
            'next_url' => $page < $pages ? $link($page + 1) : null,
        ]);
    }

    /** @param array<string,string> $errors */
    private function renderClosing(
        Request $req,
        Response $res,
        string $action = '',
        string $date = '',
        string $reason = '',
        array $errors = []
    ): Response {
        $closedUntil = (new ClosingService(($this->pdo)()))->closedUntil();
        return $req->getAttribute('view')->render($res, 'admin/closing.twig', [
            'closed_until' => $closedUntil,
            'errors' => $errors,
            'action' => $action,
            'close_date' => $action === 'close' ? $date : '',
            'reopen_date' => $action === 'reopen' ? $date : '',
            'reason' => $reason,
        ]);
    }
}
