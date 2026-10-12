<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Ledger\AccountRepository;
use Vereinskiste\Ledger\ClosedPeriod;
use Vereinskiste\Ledger\TransferService;
use Vereinskiste\Ledger\ValidationError;

/** Umbuchungen zwischen eigenen Konten anlegen (Stornieren laeuft ueber die Buchungsseiten). */
final class TransferController extends LedgerController
{
    private const TRANSFER_FIELDS = ['from_account_id', 'to_account_id', 'booking_date', 'amount', 'description'];

    public function showTransfer(Request $req, Response $res): Response
    {
        return $this->renderTransfer($req, $res, [
            'from_account_id' => '', 'to_account_id' => '', 'booking_date' => date('Y-m-d'), 'amount' => '', 'description' => '',
        ]);
    }

    public function createTransfer(Request $req, Response $res): Response
    {
        $body = $this->body($req);
        $input = [];
        foreach (self::TRANSFER_FIELDS as $field) {
            $input[$field] = $this->str($body, $field);
        }
        $accountId = static fn (string $v): int => preg_match('/^[1-9]\d{0,17}\z/', $v) === 1 ? (int) $v : 0;

        try {
            [$out] = (new TransferService(($this->pdo)()))->create(
                $accountId($input['from_account_id']),
                $accountId($input['to_account_id']),
                $input['amount'],
                $input['booking_date'],
                $input['description'],
                $this->userId($req)
            );
        } catch (ValidationError $e) {
            return $this->renderTransfer($req, $res->withStatus(422), $input, $e->errors);
        } catch (ClosedPeriod $e) {
            return $this->closedPeriod($req, $e);
        }
        $this->flash($req, 'bookings.flash.transfer_created');
        return $this->redirect('/bookings/' . $out['id']);
    }

    /**
     * @param array<string,string> $input
     * @param array<string,string> $errors
     */
    private function renderTransfer(Request $req, Response $res, array $input, array $errors = []): Response
    {
        return $req->getAttribute('view')->render($res, 'bookings/transfer.twig', [
            'input' => $input,
            'errors' => $errors,
            'accounts' => array_values(array_filter(
                (new AccountRepository(($this->pdo)()))->all(),
                static fn (array $a): bool => $a['active']
            )),
        ]);
    }
}
