<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Vereinskiste\Members\ApplicationPdf;
use Vereinskiste\Members\ApplicationSignatures;

final class ApplicationPdfDownload
{
    public static function respond(PDO $pdo, Response $response, array $application, ?int $actor): Response
    {
        $id = (int) $application['id'];
        $signatures = new ApplicationSignatures($pdo);
        $rows = $signatures->forApplication($id);
        $pngs = [];
        foreach ($rows as $row) {
            if ($row['has_signature']) {
                $pngs[$row['id']] = (string) $signatures->png($id, $row['id']);
            }
        }
        $club = (new \Vereinskiste\Club\Profile($pdo))->name();
        $body = ApplicationPdf::render($application, $rows, $pngs, $club);
        (new \Vereinskiste\Audit\AuditLog($pdo))->record('application_pdf_downloaded', $actor, ['application_id' => $id]);
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="mitgliedsantrag-' . $id . '.pdf"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
