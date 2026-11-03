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

        $pagination = Pagination::from($query, $log->count($action), '/audit-log', $action === null ? [] : ['action' => $action], [50, 100, 200]);

        return $req->getAttribute('view')->render($res, 'admin/audit.twig', [
            'entries' => $log->page($action, $pagination->perPage, $pagination->offset()),
            'actions' => AuditLog::ACTIONS,
            'action' => $action,
            'pagination' => $pagination->view(),
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
