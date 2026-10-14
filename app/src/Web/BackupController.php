<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;
use Vereinskiste\Audit\AuditLog;
use Vereinskiste\Backup\BackupService;

final class BackupController extends AdminController
{
    /** @param Closure():\PDO $pdo */
    public function __construct(
        private Closure $pdo,
        private BackupService $backupService
    ) {
    }

    public function index(Request $req, Response $res): Response
    {
        return $this->render($req, $res);
    }

    public function downloadFull(Request $req, Response $res): Response
    {
        $zipPath = $this->backupService->createFullBackupZip();
        try {
            $filename = 'vereinskiste-backup-' . date('Y-m-d_His') . '.zip';
            $content = (string) file_get_contents($zipPath);
            $res->getBody()->write($content);

            (new AuditLog(($this->pdo)()))->record('backup_created', $this->userId($req), [
                'type' => 'full',
                'bytes' => strlen($content),
            ]);

            return $res
                ->withHeader('Content-Type', 'application/zip')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('Cache-Control', 'private, no-store');
        } finally {
            @unlink($zipPath);
        }
    }

    public function downloadDatabase(Request $req, Response $res): Response
    {
        $zipPath = $this->backupService->createDatabaseBackupZip();
        try {
            $filename = 'vereinskiste-datenbank-' . date('Y-m-d_His') . '.zip';
            $content = (string) file_get_contents($zipPath);
            $res->getBody()->write($content);

            (new AuditLog(($this->pdo)()))->record('backup_created', $this->userId($req), [
                'type' => 'database',
                'bytes' => strlen($content),
            ]);

            return $res
                ->withHeader('Content-Type', 'application/zip')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('Cache-Control', 'private, no-store');
        } finally {
            @unlink($zipPath);
        }
    }

    public function downloadCsvPackage(Request $req, Response $res): Response
    {
        $zipPath = $this->backupService->createCsvPackageZip();
        try {
            $filename = 'vereinskiste-datenexport-' . date('Y-m-d') . '.zip';
            $content = (string) file_get_contents($zipPath);
            $res->getBody()->write($content);

            (new AuditLog(($this->pdo)()))->record('data_exported', $this->userId($req), [
                'type' => 'csv_package',
            ]);

            return $res
                ->withHeader('Content-Type', 'application/zip')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('Cache-Control', 'private, no-store');
        } finally {
            @unlink($zipPath);
        }
    }

    public function downloadAccountsCsv(Request $req, Response $res): Response
    {
        $csv = $this->backupService->exportAccountsCsv();
        $res->getBody()->write($csv);

        (new AuditLog(($this->pdo)()))->record('data_exported', $this->userId($req), ['type' => 'accounts_csv']);

        return $res
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="konten.csv"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    public function downloadCategoriesCsv(Request $req, Response $res): Response
    {
        $csv = $this->backupService->exportCategoriesCsv();
        $res->getBody()->write($csv);

        (new AuditLog(($this->pdo)()))->record('data_exported', $this->userId($req), ['type' => 'categories_csv']);

        return $res
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="kategorien.csv"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    public function downloadInvoicesCsv(Request $req, Response $res): Response
    {
        $csv = $this->backupService->exportInvoicesCsv();
        $res->getBody()->write($csv);

        (new AuditLog(($this->pdo)()))->record('data_exported', $this->userId($req), ['type' => 'invoices_csv']);

        return $res
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="rechnungen.csv"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    public function downloadInvoicesPackage(Request $req, Response $res): Response
    {
        $zipPath = $this->backupService->createInvoicesPackageZip();
        try {
            $filename = 'vereinskiste-rechnungen-' . date('Y-m-d') . '.zip';
            $content = (string) file_get_contents($zipPath);
            $res->getBody()->write($content);

            (new AuditLog(($this->pdo)()))->record('data_exported', $this->userId($req), [
                'type' => 'invoices_package',
            ]);

            return $res
                ->withHeader('Content-Type', 'application/zip')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('Cache-Control', 'private, no-store');
        } finally {
            @unlink($zipPath);
        }
    }

    public function verify(Request $req, Response $res): Response
    {
        $files = $req->getUploadedFiles();
        $uploaded = $files['backup_file'] ?? null;

        if (!$uploaded instanceof UploadedFileInterface || $uploaded->getError() !== UPLOAD_ERR_OK) {
            $this->flash($req, 'settings.backup.restore.error_upload');
            return $this->redirect('/settings/backup');
        }

        $tmpPath = sys_get_temp_dir() . '/vk_verify_' . bin2hex(random_bytes(8)) . '.zip';
        try {
            $uploaded->moveTo($tmpPath);
            $verifyResult = $this->backupService->verifyBackupZip($tmpPath);
            return $this->render($req, $res, $verifyResult);
        } catch (Throwable $e) {
            return $this->render($req, $res, [
                'valid' => false,
                'errors' => ['Prüfung fehlgeschlagen: ' . $e->getMessage()],
            ]);
        } finally {
            @unlink($tmpPath);
        }
    }

    public function restore(Request $req, Response $res): Response
    {
        $body = $this->body($req);
        $confirmCheckbox = ($body['confirm_overwrite'] ?? '') === '1';
        $confirmPhrase = trim((string) ($body['confirm_phrase'] ?? ''));

        if (!$confirmCheckbox || mb_strtoupper($confirmPhrase, 'UTF-8') !== 'WIEDERHERSTELLEN') {
            $this->flash($req, 'settings.backup.restore.error_confirm');
            return $this->redirect('/settings/backup');
        }

        $files = $req->getUploadedFiles();
        $uploaded = $files['backup_file'] ?? null;

        if (!$uploaded instanceof UploadedFileInterface || $uploaded->getError() !== UPLOAD_ERR_OK) {
            $this->flash($req, 'settings.backup.restore.error_upload');
            return $this->redirect('/settings/backup');
        }

        $tmpPath = sys_get_temp_dir() . '/vk_restore_' . bin2hex(random_bytes(8)) . '.zip';
        try {
            $uploaded->moveTo($tmpPath);
            $this->backupService->restoreBackupZip($tmpPath, $this->userId($req));
            $this->flash($req, 'settings.backup.restore.success');
        } catch (Throwable $e) {
            $this->flash($req, 'settings.backup.restore.error_failed');
        } finally {
            @unlink($tmpPath);
        }

        return $this->redirect('/settings/backup');
    }

    /** @param array<string,mixed>|null $verifyResult */
    private function render(Request $req, Response $res, ?array $verifyResult = null): Response
    {
        $stats = $this->backupService->getStats();
        return $req->getAttribute('view')->render($res, 'settings/backup.twig', [
            'stats' => $stats,
            'verify_result' => $verifyResult,
        ]);
    }
}
