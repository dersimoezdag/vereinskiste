<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Http\IterableStream;
use Vereinskiste\Ledger\AccountRepository;
use Vereinskiste\Ledger\BookingRepository;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Reports\CsvExporter;
use Vereinskiste\Reports\PdfExporter;
use Vereinskiste\Reports\SummaryService;

final class ReportController
{
    private const FALLBACK_CLUB_NAME = 'Verein';

    /**
     * @param Closure():\PDO $pdo
     * @param array<string,string>|null $texts Textschluessel => Text (fuer die Exporte)
     */
    public function __construct(private Closure $pdo, private ?array $texts = null)
    {
    }

    public function summary(Request $req, Response $res): Response
    {
        return $this->withSummary($req, $res, fn (array $summary, array $input): Response => $req
            ->getAttribute('view')->render($res, 'reports/summary.twig', $this->context($input, [], $summary)));
    }

    public function pdf(Request $req, Response $res): Response
    {
        return $this->withSummary($req, $res, function (array $summary) use ($res): Response {
            $club = $this->clubName();
            $bytes = (new PdfExporter($this->texts))->summary($summary, $club);
            $res->getBody()->write($bytes);
            return $res
                ->withHeader('Content-Type', 'application/pdf')
                ->withHeader('Content-Disposition', 'attachment; filename="uebersicht_' . $summary['from'] . '_' . $summary['to'] . '.pdf"')
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('Cache-Control', 'private, no-store');
        });
    }

    public function csv(Request $req, Response $res): Response
    {
        $filter = BookingRepository::normalizeFilter($req->getQueryParams());
        if (isset($filter['from'], $filter['to']) && $filter['from'] > $filter['to']) {
            // Gleiche Rueckmeldung wie auf der Uebersichtsseite.
            return $this->withSummary($req, $res, static fn (): Response => $res);
        }

        $name = match (true) {
            isset($filter['from'], $filter['to']) => $filter['from'] . '_' . $filter['to'],
            isset($filter['from']) => 'ab_' . $filter['from'],
            isset($filter['to']) => 'bis_' . $filter['to'],
            default => 'alle',
        };
        $chunks = (new CsvExporter(($this->pdo)(), 500, $this->texts))->bookings($filter);
        return $res
            ->withBody(new IterableStream($chunks))
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="buchungen_' . $name . '.csv"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * Zeitraum und Konto aus der Query lesen (Standard: laufendes Kalenderjahr), die Auswertung berechnen und
     * `$onSuccess` aufrufen; bei Eingabefehlern stattdessen die Seite mit Fehlern (422) ausgeben.
     *
     * @param callable(array<string,mixed>,array<string,string>):Response $onSuccess
     */
    private function withSummary(Request $req, Response $res, callable $onSuccess): Response
    {
        $q = $req->getQueryParams();
        $input = [
            'from' => $this->param($q, 'from') ?: date('Y-01-01'),
            'to' => $this->param($q, 'to') ?: date('Y-12-31'),
            'account_id' => $this->param($q, 'account_id'),
        ];

        $accountId = null;
        if ($input['account_id'] !== '' && preg_match('/^[1-9]\d{0,17}\z/', $input['account_id']) === 1) {
            $accountId = (int) $input['account_id'];
        }
        try {
            if ($input['account_id'] !== '' && $accountId === null) {
                throw new ValidationError(['account_id' => 'validation.booking.account']);
            }
            $summary = (new SummaryService(($this->pdo)()))->summary($input['from'], $input['to'], $accountId);
        } catch (ValidationError $e) {
            return $req->getAttribute('view')->render(
                $res->withStatus(422),
                'reports/summary.twig',
                $this->context($input, $e->errors, null)
            );
        }
        return $onSuccess($summary, $input);
    }

    /**
     * @param array<string,string> $input
     * @param array<string,string> $errors
     * @param array<string,mixed>|null $summary
     * @return array<string,mixed>
     */
    private function context(array $input, array $errors, ?array $summary): array
    {
        $export = array_filter(
            ['from' => $input['from'], 'to' => $input['to'], 'account_id' => $input['account_id']],
            static fn (string $v): bool => $v !== ''
        );
        return [
            'input' => $input,
            'errors' => $errors,
            'summary' => $summary,
            'accounts' => (new AccountRepository(($this->pdo)()))->all(),
            'export_query' => $errors === [] ? http_build_query($export) : '',
        ];
    }

    /** @param array<string,mixed> $query */
    private function param(array $query, string $key): string
    {
        return is_string($query[$key] ?? null) ? trim($query[$key]) : '';
    }

    private function clubName(): string
    {
        $stmt = ($this->pdo)()->prepare("SELECT value FROM settings WHERE key = 'club_name'");
        $stmt->execute();
        $name = $stmt->fetchColumn();
        return is_string($name) && trim($name) !== '' ? trim($name) : self::FALLBACK_CLUB_NAME;
    }
}
