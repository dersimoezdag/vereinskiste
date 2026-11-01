<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Club\Profile;
use Vereinskiste\Ledger\AccountRepository;
use Vereinskiste\Ledger\DateInput;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Reports\CashOverview;
use Vereinskiste\Reports\DashboardService;
use Vereinskiste\Reports\SummaryService;

/** Startseite der Vereinskasse: Kontostaende, Einnahmen gegen Ausgaben, Diagramme, laufender Saldo. */
final class CashbookController extends AdminController
{
    public function __construct(private Closure $pdo) {}

    public function index(Request $req, Response $res): Response
    {
        $pdo = ($this->pdo)();
        $query = $req->getQueryParams();
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Berlin'));
        $from = $this->str($query, 'from');
        $to = $this->str($query, 'to');
        $from = DateInput::valid($from) ? $from : (new Profile($pdo))->period($today)['from'];
        $to = DateInput::valid($to) ? $to : $today->format('Y-m-d');
        $accounts = (new AccountRepository($pdo))->all();
        $rawAccount = (int) $this->str($query, 'account_id');
        $accountId = in_array($rawAccount, array_map('intval', array_column($accounts, 'id')), true) ? $rawAccount : null;
        $overview = new CashOverview($pdo);
        $context = $overview->balances() + [
            'filter' => ['from' => $from, 'to' => $to, 'account_id' => $accountId],
            'account_options' => $accounts,
            'ledger' => $overview->ledger($accountId),
            'open_expenses' => (new \Vereinskiste\Expenses\ExpenseRepository($pdo))->openSummary(),
            'errors' => [],
            'summary' => null,
        ];
        try {
            $summary = (new SummaryService($pdo))->summary($from, $to, $accountId);
            $monthly = (new DashboardService($pdo))->monthly($from, $to, $accountId);
            $context = ['summary' => $summary, 'monthly' => $monthly]
                + DashboardService::chartContext($summary, $monthly) + $context;
        } catch (ValidationError $e) {
            $context['errors'] = $e->errors;
            $res = $res->withStatus(422);
        }
        return $req->getAttribute('view')->render($res, 'cashbook/index.twig', $context);
    }
}
