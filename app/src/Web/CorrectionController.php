<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Ledger\BookingRepository;
use Vereinskiste\Ledger\BookingService;
use Vereinskiste\Ledger\ClosedPeriod;
use Vereinskiste\Ledger\ValidationError;

/** Korrekturbuchungen (eigene, sichtbare Buchung; auch nach einem Abschluss moeglich). */
final class CorrectionController extends LedgerController
{
    public function showCorrection(Request $req, Response $res): Response
    {
        $of = $req->getQueryParams()['of'] ?? null;
        $original = null;
        $input = [];
        if (is_string($of) && preg_match('/^[1-9]\d{0,17}\z/', $of) === 1) {
            $original = (new BookingRepository(($this->pdo)()))->find((int) $of);
            if ($original === null) {
                return $this->notFound($req);
            }
            $input = ['account_id' => (string) $original['account_id']];
        }
        return $this->renderForm($req, $res, 'bookings/correction.twig', [
            'input' => $input + ['description' => ''],
            'original' => $original,
            'correction_of_id' => $original === null ? '' : (string) $original['id'],
            'reason' => '',
        ]);
    }

    public function createCorrection(Request $req, Response $res): Response
    {
        $pdo = ($this->pdo)();
        $body = $this->body($req);
        $input = $this->formInput($body);
        $reason = $this->str($body, 'reason');
        $rawOf = $this->str($body, 'correction_of_id');
        // Unbrauchbarer Verweis wird zu 0 und vom Service als "gibt es nicht" gemeldet.
        $of = $rawOf === '' ? null : (preg_match('/^[1-9]\d{0,17}\z/', $rawOf) === 1 ? (int) $rawOf : 0);

        try {
            $booking = (new BookingService($pdo))->createCorrection($input, $of, $reason, $this->userId($req));
        } catch (ValidationError $e) {
            return $this->renderForm($req, $res->withStatus(422), 'bookings/correction.twig', [
                'input' => $input,
                'errors' => $e->errors,
                'original' => $of === null ? null : (new BookingRepository($pdo))->find($of),
                'correction_of_id' => $rawOf,
                'reason' => $reason,
            ]);
        } catch (ClosedPeriod $e) {
            return $this->closedPeriod($req, $e);
        }
        $this->flash($req, 'bookings.flash.correction_created');
        return $this->redirect('/bookings/' . $booking['id']);
    }
}
