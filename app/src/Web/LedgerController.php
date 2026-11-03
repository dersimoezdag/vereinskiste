<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Ledger\AccountRepository;
use Vereinskiste\Ledger\CategoryRepository;

/** Gemeinsame Helfer der Buchungs-, Korrektur- und Umbuchungsseiten. */
abstract class LedgerController extends AdminController
{
    protected const FORM_FIELDS = [
        'account_id', 'booking_date', 'amount', 'direction', 'category_id', 'description', 'counterparty', 'receipt_no', 'finance_contact_id', 'event_id', 'funding_id',
    ];

    /** @param Closure():\PDO $pdo */
    public function __construct(protected Closure $pdo)
    {
    }

    /**
     * Formular mit Konto- und Kategorieauswahl. Angeboten werden aktive Eintraege; ein bereits
     * gewaehlter inaktiver Eintrag (Bearbeitung) bleibt waehlbar, damit er nicht stillschweigend wechselt.
     *
     * @param array<string,mixed> $context input?, errors?, booking?
     */
    protected function renderForm(Request $req, Response $res, string $template, array $context = []): Response
    {
        $pdo = ($this->pdo)();
        $context['input'] = ($context['input'] ?? []) + [
            'account_id' => '', 'booking_date' => date('Y-m-d'), 'amount' => '', 'direction' => 'out',
            'category_id' => '', 'description' => '', 'counterparty' => '', 'receipt_no' => '', 'finance_contact_id' => '', 'event_id' => '', 'funding_id' => '',
        ];
        $context['errors'] ??= [];
        $keep = static fn (string $key): ?int => isset($context['booking'][$key]) ? (int) $context['booking'][$key] : null;
        $context['accounts'] = array_values(array_filter(
            (new AccountRepository($pdo))->all(),
            static fn (array $a): bool => $a['active'] || $a['id'] === $keep('account_id')
        ));
        $context['categories'] = array_values(array_filter(
            (new CategoryRepository($pdo))->all(),
            static fn (array $c): bool => $c['active'] || $c['id'] === $keep('category_id')
        ));
        $context['contacts'] = $pdo->query('SELECT id, name, active FROM finance_contacts ORDER BY lower(name), id')->fetchAll();
        $context['events'] = (new \Vereinskiste\Events\EventRepository($pdo))->options($keep('event_id'));
        $context['funding_applications'] = (new \Vereinskiste\Funding\FundingRepository($pdo))->options($keep('funding_id'));
        return $req->getAttribute('view')->render($res, $template, $context);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,string>
     */
    protected function formInput(array $body): array
    {
        $input = [];
        foreach (self::FORM_FIELDS as $field) {
            $input[$field] = $this->str($body, $field);
        }
        // Die Betragsmaske zeigt das Vorzeichen der Richtung; gespeichert wird der Betrag ohne Vorzeichen.
        if (preg_match('/^([+-])\s*(.*)\z/su', $input['amount'], $match) === 1) {
            $input['amount'] = $match[2];
            $input['direction'] = $match[1] === '-' ? 'out' : 'in';
        }
        return $input;
    }

    protected function userId(Request $req): int
    {
        return $req->getAttribute('auth')->currentUser()['id'];
    }
}
