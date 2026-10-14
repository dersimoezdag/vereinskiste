<?php
declare(strict_types=1);

namespace Vereinskiste\Backup;

use Closure;
use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;
use Vereinskiste\Audit\AuditLog;
use Vereinskiste\Contacts\ContactExport;
use Vereinskiste\Members\MemberExport;
use Vereinskiste\Reports\CsvExporter;
use Vereinskiste\Update\DatabaseBackup;
use Vereinskiste\Update\DatabaseRestore;
use Vereinskiste\Update\DatabaseSchema;
use Vereinskiste\Update\Files;

final class BackupService
{
    public const MANIFEST_FILE = 'manifest.json';
    public const FORMAT_NAME = 'vereinskiste-backup-v1';

    public function __construct(
        private Closure|PDO $pdo,
        private string $appDir,
        private string $receiptsDir,
        private string $documentsDir,
        private string $configDir,
        private string $version,
        private ?array $texts = null
    ) {
    }

    private function pdo(): PDO
    {
        return $this->pdo instanceof Closure ? ($this->pdo)() : $this->pdo;
    }

    /**
     * Ermittelt statistische Kennzahlen über den aktuellen Datenbestand.
     *
     * @return array<string,mixed>
     */
    public function getStats(): array
    {
        $clubName = $this->getClubName();
        $bookingsCount = (int) ($this->scalar('SELECT count(*) FROM bookings') ?? 0);
        $membersCount = (int) ($this->scalar('SELECT count(*) FROM members') ?? 0);
        $contactsCount = (int) ($this->scalar('SELECT count(*) FROM finance_contacts') ?? 0);
        $invoicesCount = (int) ($this->scalar('SELECT count(*) FROM invoices') ?? 0);
        $accountsCount = (int) ($this->scalar('SELECT count(*) FROM accounts') ?? 0);
        $categoriesCount = (int) ($this->scalar('SELECT count(*) FROM categories') ?? 0);
        $invoiceDocsCount = (int) ($this->scalar('SELECT count(*) FROM invoice_documents') ?? 0);

        $tables = (new DatabaseSchema($this->pdo()))->tables();
        $totalDbRows = 0;
        foreach ($tables as $table) {
            $totalDbRows += (int) ($this->scalar('SELECT count(*) FROM public.' . DatabaseSchema::quote($table)) ?? 0);
        }

        $receipts = $this->dirStats($this->receiptsDir);
        $documents = $this->dirStats($this->documentsDir);
        $databaseFiles = $this->pdo()->query('SELECT count(*) AS count, COALESCE(sum(size),0) AS bytes FROM document_files')->fetch();
        $documents['count'] += (int) $databaseFiles['count'];
        $documents['bytes'] += (int) $databaseFiles['bytes'];
        $totalFiles = $receipts['count'] + $documents['count'];
        $totalBytes = $receipts['bytes'] + $documents['bytes'];

        return [
            'club_name' => $clubName,
            'version' => $this->version,
            'tables_count' => count($tables),
            'total_db_rows' => $totalDbRows,
            'bookings_count' => $bookingsCount,
            'members_count' => $membersCount,
            'contacts_count' => $contactsCount,
            'invoices_count' => $invoicesCount,
            'accounts_count' => $accountsCount,
            'categories_count' => $categoriesCount,
            'invoice_docs_count' => $invoiceDocsCount,
            'receipts_count' => $receipts['count'],
            'receipts_bytes' => $receipts['bytes'],
            'documents_count' => $documents['count'],
            'documents_bytes' => $documents['bytes'],
            'total_files_count' => $totalFiles,
            'total_files_bytes' => $totalBytes,
            'total_files_formatted' => self::formatBytes($totalBytes),
        ];
    }

    /**
     * Erstellt ein vollständiges System-Backup (Datenbank, Belege, Dokumente, Konfiguration und Manifest).
     *
     * @return string Pfad zur erzeugten temporären ZIP-Datei
     */
    public function createFullBackupZip(): string
    {
        $workDir = $this->createTempDir('vk_backup_full_');
        $zipPath = sys_get_temp_dir() . '/vk_backup_' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(4)) . '.zip';

        try {
            $dbDir = $workDir . '/database';
            $this->dumpDatabase($dbDir);
            $this->generateSqlDump($dbDir, $workDir . '/database.sql');

            $this->copyDirectory($this->receiptsDir, $workDir . '/storage/receipts');
            $this->copyDirectory($this->documentsDir, $workDir . '/storage/documents');

            if (is_file($this->configDir . '/config.php')) {
                Files::mkdir($workDir . '/config');
                copy($this->configDir . '/config.php', $workDir . '/config/config.php');
            }

            $manifest = [
                'format' => self::FORMAT_NAME,
                'type' => 'full',
                'version' => $this->version,
                'created_at' => date('c'),
                'club_name' => $this->getClubName(),
                'stats' => $this->getStats(),
            ];
            file_put_contents($workDir . '/' . self::MANIFEST_FILE, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $this->zipDirectory($workDir, $zipPath);
            return $zipPath;
        } finally {
            $this->deleteDir($workDir);
        }
    }

    /**
     * Erstellt eine reine Datenbanksicherung (PostgreSQL-Dump, JSONL-Tabellen und Manifest).
     *
     * @return string Pfad zur erzeugten temporären ZIP-Datei
     */
    public function createDatabaseBackupZip(): string
    {
        $workDir = $this->createTempDir('vk_backup_db_');
        $zipPath = sys_get_temp_dir() . '/vk_db_' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(4)) . '.zip';

        try {
            $dbDir = $workDir . '/database';
            $this->dumpDatabase($dbDir);
            $this->generateSqlDump($dbDir, $workDir . '/database.sql');

            $manifest = [
                'format' => self::FORMAT_NAME,
                'type' => 'database',
                'version' => $this->version,
                'created_at' => date('c'),
                'club_name' => $this->getClubName(),
                'stats' => $this->getStats(),
            ];
            file_put_contents($workDir . '/' . self::MANIFEST_FILE, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $this->zipDirectory($workDir, $zipPath);
            return $zipPath;
        } finally {
            $this->deleteDir($workDir);
        }
    }

    /**
     * Erstellt ein CSV-Gesamtpaket mit allen Fachdaten als ZIP-Archiv.
     *
     * @return string Pfad zur erzeugten temporären ZIP-Datei
     */
    public function createCsvPackageZip(): string
    {
        $workDir = $this->createTempDir('vk_csv_all_');
        $zipPath = sys_get_temp_dir() . '/vk_export_' . date('Y-m-d') . '_' . bin2hex(random_bytes(4)) . '.zip';

        try {
            // Buchungen
            $bookingExporter = new CsvExporter($this->pdo(), 500, $this->texts);
            $bookingFile = fopen($workDir . '/buchungen.csv', 'wb');
            if ($bookingFile === false) {
                throw new RuntimeException('Konnte Buchungsdatei nicht anlegen.');
            }
            try {
                foreach ($bookingExporter->bookings([]) as $chunk) {
                    fwrite($bookingFile, $chunk);
                }
            } finally {
                fclose($bookingFile);
            }

            // Mitglieder
            $memberStream = (new MemberExport($this->pdo()))->csv();
            $memberFile = fopen($workDir . '/mitglieder.csv', 'wb');
            if ($memberFile !== false) {
                try {
                    stream_copy_to_stream($memberStream, $memberFile);
                } finally {
                    fclose($memberFile);
                    if (is_resource($memberStream)) {
                        fclose($memberStream);
                    }
                }
            }

            // Finanzkontakte
            $contactStream = (new ContactExport($this->pdo()))->csv();
            $contactFile = fopen($workDir . '/finanzkontakte.csv', 'wb');
            if ($contactFile !== false) {
                try {
                    stream_copy_to_stream($contactStream, $contactFile);
                } finally {
                    fclose($contactFile);
                    if (is_resource($contactStream)) {
                        fclose($contactStream);
                    }
                }
            }

            // Konten & Kategorien
            file_put_contents($workDir . '/konten.csv', $this->exportAccountsCsv());
            file_put_contents($workDir . '/kategorien.csv', $this->exportCategoriesCsv());

            // Rechnungen
            file_put_contents($workDir . '/rechnungen.csv', $this->exportInvoicesCsv());

            // Rechnungsdateien exportieren
            $invoiceDocs = $this->getInvoiceDocumentsGrouped();
            if ($invoiceDocs !== []) {
                Files::mkdir($workDir . '/rechnungen');
                foreach ($invoiceDocs as $docs) {
                    foreach ($docs as $doc) {
                        if (is_file($doc['source_path'])) {
                            copy($doc['source_path'], $workDir . '/rechnungen/' . $doc['archive_name']);
                        }
                    }
                }
            }

            // Buchungsbelege exportieren
            $receipts = $this->getBookingReceiptsGrouped();
            if ($receipts !== []) {
                Files::mkdir($workDir . '/belege');
                foreach ($receipts as $recs) {
                    foreach ($recs as $rec) {
                        if (is_file($rec['source_path'])) {
                            copy($rec['source_path'], $workDir . '/belege/' . $rec['archive_name']);
                        }
                    }
                }
            }

            $this->zipDirectory($workDir, $zipPath);
            return $zipPath;
        } finally {
            $this->deleteDir($workDir);
        }
    }

    /**
     * Erstellt ein ZIP-Archiv mit der Rechnungsliste (CSV) und allen zugehörigen Originaldateien (PDF, XML, Bilder).
     *
     * @return string Pfad zur erzeugten temporären ZIP-Datei
     */
    public function createInvoicesPackageZip(): string
    {
        $workDir = $this->createTempDir('vk_invoices_export_');
        $zipPath = sys_get_temp_dir() . '/vk_rechnungen_' . date('Y-m-d') . '_' . bin2hex(random_bytes(4)) . '.zip';

        try {
            file_put_contents($workDir . '/rechnungen.csv', $this->exportInvoicesCsv());

            $invoiceDocs = $this->getInvoiceDocumentsGrouped();
            if ($invoiceDocs !== []) {
                Files::mkdir($workDir . '/rechnungen');
                foreach ($invoiceDocs as $docs) {
                    foreach ($docs as $doc) {
                        if (is_file($doc['source_path'])) {
                            copy($doc['source_path'], $workDir . '/rechnungen/' . $doc['archive_name']);
                        }
                    }
                }
            }

            $this->zipDirectory($workDir, $zipPath);
            return $zipPath;
        } finally {
            $this->deleteDir($workDir);
        }
    }

    /**
     * Ermittelt alle an Rechnungen angehängten Dokumente gruppiert nach invoice_id.
     *
     * @return array<int, list<array{document_id: int, file_id: int, role: string, original_name: string, storage_name: string, archive_name: string, archive_rel_path: string, source_path: string}>>
     */
    public function getInvoiceDocumentsGrouped(): array
    {
        $stmt = $this->pdo()->query("
            SELECT d.id AS document_id, d.invoice_id, d.role,
                   f.id AS file_id, f.original_name, f.storage_name, f.root
            FROM invoice_documents d
            JOIN stored_files f ON f.id = d.file_id
            ORDER BY d.invoice_id, d.id
        ");

        $grouped = [];
        $countsPerInvoice = [];

        while ($row = $stmt->fetch()) {
            $invId = (int) $row['invoice_id'];
            $docId = (int) $row['document_id'];
            $origName = basename((string) $row['original_name']);
            $storageName = (string) $row['storage_name'];

            $cleanBase = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $origName) ?: ('dokument_' . $docId);
            $cleanBase = ltrim($cleanBase, '.');
            if ($cleanBase === '') {
                $cleanBase = 'dokument_' . $docId;
            }

            $archiveName = $invId . '_' . $cleanBase;
            if (isset($countsPerInvoice[$invId][$archiveName])) {
                $ext = pathinfo($cleanBase, PATHINFO_EXTENSION);
                $nameWithoutExt = pathinfo($cleanBase, PATHINFO_FILENAME);
                $archiveName = $invId . '_' . $nameWithoutExt . '_' . $docId . ($ext !== '' ? '.' . $ext : '');
            }
            $countsPerInvoice[$invId][$archiveName] = true;

            $sourcePath = $this->documentsDir . '/' . $storageName;

            $grouped[$invId][] = [
                'document_id' => $docId,
                'file_id' => (int) $row['file_id'],
                'role' => (string) $row['role'],
                'original_name' => $origName,
                'storage_name' => $storageName,
                'archive_name' => $archiveName,
                'archive_rel_path' => 'rechnungen/' . $archiveName,
                'source_path' => $sourcePath,
            ];
        }

        return $grouped;
    }

    /**
     * Ermittelt alle Buchungsbelege gruppiert nach booking_id.
     *
     * @return array<int, list<array{receipt_id: int, booking_id: int, original_name: string, storage_name: string, archive_name: string, archive_rel_path: string, source_path: string}>>
     */
    public function getBookingReceiptsGrouped(): array
    {
        $stmt = $this->pdo()->query("
            SELECT r.id AS receipt_id, r.booking_id, r.original_name, r.storage_name
            FROM receipts r
            WHERE r.replaced_at IS NULL
            ORDER BY r.booking_id, r.id
        ");

        $grouped = [];
        $countsPerBooking = [];

        while ($row = $stmt->fetch()) {
            $bookingId = (int) $row['booking_id'];
            $receiptId = (int) $row['receipt_id'];
            $origName = basename((string) $row['original_name']);
            $storageName = (string) $row['storage_name'];

            $cleanBase = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $origName) ?: ('beleg_' . $receiptId);
            $cleanBase = ltrim($cleanBase, '.');
            if ($cleanBase === '') {
                $cleanBase = 'beleg_' . $receiptId;
            }

            $archiveName = $bookingId . '_' . $receiptId . '_' . $cleanBase;
            $sourcePath = $this->receiptsDir . '/' . $storageName;

            $grouped[$bookingId][] = [
                'receipt_id' => $receiptId,
                'booking_id' => $bookingId,
                'original_name' => $origName,
                'storage_name' => $storageName,
                'archive_name' => $archiveName,
                'archive_rel_path' => 'belege/' . $archiveName,
                'source_path' => $sourcePath,
            ];
        }

        return $grouped;
    }

    /**
     * Exportiert Konten als formatierte CSV-Zeichenkette.
     */
    public function exportAccountsCsv(): string
    {
        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            throw new RuntimeException('Speicherstrom nicht verfügbar.');
        }
        fwrite($stream, CsvExporter::BOM);
        fputcsv($stream, ['ID', 'Name', 'Typ', 'Anfangssaldo (EUR)', 'Eröffnungsdatum', 'Aktiv'], ';', '"', '');

        $stmt = $this->pdo()->query('SELECT id, name, type, opening_balance, opening_date, active FROM accounts ORDER BY id');
        while ($row = $stmt->fetch()) {
            fputcsv($stream, [
                (string) $row['id'],
                CsvExporter::safe((string) $row['name']),
                $row['type'] === 'cash' ? 'Barkasse' : 'Bankkonto',
                str_replace('.', ',', (string) $row['opening_balance']),
                (string) $row['opening_date'],
                $row['active'] ? 'Ja' : 'Nein',
            ], ';', '"', '');
        }
        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fclose($stream);
        return $content;
    }

    /**
     * Exportiert Kategorien als formatierte CSV-Zeichenkette.
     */
    public function exportCategoriesCsv(): string
    {
        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            throw new RuntimeException('Speicherstrom nicht verfügbar.');
        }
        fwrite($stream, CsvExporter::BOM);
        fputcsv($stream, ['ID', 'Name', 'Aktiv'], ';', '"', '');

        $stmt = $this->pdo()->query('SELECT id, name, active FROM categories ORDER BY id');
        while ($row = $stmt->fetch()) {
            fputcsv($stream, [
                (string) $row['id'],
                CsvExporter::safe((string) $row['name']),
                $row['active'] ? 'Ja' : 'Nein',
            ], ';', '"', '');
        }
        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fclose($stream);
        return $content;
    }

    /**
     * Exportiert Rechnungen und Auslagen als formatierte CSV-Zeichenkette inklusive Dateireferenzen.
     */
    public function exportInvoicesCsv(): string
    {
        $docsGrouped = $this->getInvoiceDocumentsGrouped();

        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            throw new RuntimeException('Speicherstrom nicht verfügbar.');
        }
        fwrite($stream, CsvExporter::BOM);
        fputcsv($stream, [
            'ID', 'Art', 'Rechnungsnummer', 'Partner / Gegenpartei', 'Rechnungsdatum',
            'Fälligkeitsdatum', 'Netto (EUR)', 'Steuer (EUR)', 'Brutto (EUR)', 'Status', 'Notiz', 'Dateien'
        ], ';', '"', '');

        $stmt = $this->pdo()->query('SELECT id, kind, number, supplier_name, invoice_date, due_date, net, tax, gross, status, notes FROM invoices ORDER BY id');
        while ($row = $stmt->fetch()) {
            $invId = (int) $row['id'];
            $fileEntries = [];
            if (!empty($docsGrouped[$invId])) {
                foreach ($docsGrouped[$invId] as $doc) {
                    $fileEntries[] = $doc['archive_rel_path'];
                }
            }
            $statusName = match ($row['status']) {
                'draft' => 'Entwurf',
                'reviewed' => 'Geprüft',
                'cancelled' => 'Storniert',
                default => (string) $row['status'],
            };
            fputcsv($stream, [
                (string) $row['id'],
                $row['kind'] === 'credit_note' ? 'Gutschrift' : 'Rechnung',
                CsvExporter::safe((string) $row['number']),
                CsvExporter::safe((string) $row['supplier_name']),
                (string) ($row['invoice_date'] ?? ''),
                (string) ($row['due_date'] ?? ''),
                $row['net'] !== null ? str_replace('.', ',', (string) $row['net']) : '',
                $row['tax'] !== null ? str_replace('.', ',', (string) $row['tax']) : '',
                $row['gross'] !== null ? str_replace('.', ',', (string) $row['gross']) : '',
                $statusName,
                CsvExporter::safe((string) ($row['notes'] ?? '')),
                CsvExporter::safe(implode(', ', $fileEntries)),
            ], ';', '"', '');
        }
        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fclose($stream);
        return $content;
    }

    /**
     * Prüft ein hochgeladenes Backup-Archiv auf Vollständigkeit und Prüfsummen-Integrität.
     *
     * @return array{valid:bool, errors:list<string>, manifest:?array, tables_count:int, has_storage:bool, receipts_count:int, documents_count:int}
     */
    public function verifyBackupZip(string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            return [
                'valid' => false,
                'errors' => ['Das Archiv konnte nicht als ZIP-Datei geöffnet werden.'],
                'manifest' => null,
                'tables_count' => 0,
                'has_storage' => false,
                'receipts_count' => 0,
                'documents_count' => 0,
            ];
        }

        $workDir = $this->createTempDir('vk_verify_');
        try {
            $zip->extractTo($workDir);
            $zip->close();

            $dbDir = is_dir($workDir . '/database') ? $workDir . '/database' : $workDir;
            $schemaFile = $dbDir . '/schema.json';
            $completeFile = $dbDir . '/complete.json';

            $errors = [];
            if (!is_file($schemaFile)) {
                $errors[] = 'Datenbank-Schema (schema.json) fehlt im Archiv.';
            }
            if (!is_file($completeFile)) {
                $errors[] = 'Prüfsummen-Manifest (complete.json) fehlt im Archiv.';
            }

            $tablesCount = 0;
            if ($errors === []) {
                $complete = Files::read($completeFile);
                if (!is_array($complete) || !isset($complete['hashes']) || !is_array($complete['hashes'])) {
                    $errors[] = 'Das Prüfsummen-Manifest ist beschädigt.';
                } else {
                    foreach ($complete['hashes'] as $file => $expectedHash) {
                        $targetPath = $dbDir . '/' . $file;
                        if (!is_file($targetPath)) {
                            $errors[] = 'Fehlende Datenbank-Datei: ' . $file;
                            break;
                        }
                        $actualHash = hash_file('sha256', $targetPath);
                        if (!hash_equals($expectedHash, (string) $actualHash)) {
                            $errors[] = 'Prüfsummen-Abweichung in ' . $file . '. Die Datei wurde möglicherweise verändert.';
                            break;
                        }
                    }
                }

                $schema = Files::read($schemaFile);
                if (is_array($schema) && isset($schema['tables']) && is_array($schema['tables'])) {
                    $tablesCount = count($schema['tables']);
                }
            }

            $manifest = null;
            if (is_file($workDir . '/' . self::MANIFEST_FILE)) {
                $manifest = json_decode((string) file_get_contents($workDir . '/' . self::MANIFEST_FILE), true);
            }

            $receiptsCount = 0;
            if (is_dir($workDir . '/storage/receipts')) {
                $receiptsCount = $this->dirStats($workDir . '/storage/receipts')['count'];
            }
            $documentsCount = 0;
            if (is_dir($workDir . '/storage/documents')) {
                $documentsCount = $this->dirStats($workDir . '/storage/documents')['count'];
            }
            $hasStorage = $receiptsCount > 0 || $documentsCount > 0;

            return [
                'valid' => $errors === [],
                'errors' => $errors,
                'manifest' => $manifest,
                'tables_count' => $tablesCount,
                'has_storage' => $hasStorage,
                'receipts_count' => $receiptsCount,
                'documents_count' => $documentsCount,
            ];
        } finally {
            $this->deleteDir($workDir);
        }
    }

    /**
     * Stellt ein Backup-Archiv vollständig in der Datenbank und den Speicherverzeichnissen wieder her.
     *
     * @return array{tables_count:int, receipts_restored:int, documents_restored:int}
     * @throws RuntimeException
     */
    public function restoreBackupZip(string $zipPath, ?int $userId): array
    {
        $verification = $this->verifyBackupZip($zipPath);
        if (!$verification['valid']) {
            throw new RuntimeException('Ungültiges Backup-Archiv: ' . implode(' ', $verification['errors']));
        }

        $workDir = $this->createTempDir('vk_restore_run_');
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Backup-Archiv konnte nicht geöffnet werden.');
        }

        try {
            $zip->extractTo($workDir);
            $zip->close();

            $dbDir = is_dir($workDir . '/database') ? $workDir . '/database' : $workDir;

            // Datenbank transaktionssicher wiederherstellen
            (new DatabaseRestore($this->pdo(), $dbDir, 120))->run();

            // Belege und Dokumente wiederherstellen
            $receiptsRestored = 0;
            if (is_dir($workDir . '/storage/receipts')) {
                Files::mkdir($this->receiptsDir);
                foreach (scandir($workDir . '/storage/receipts') as $file) {
                    if ($file !== '.' && $file !== '..' && is_file($workDir . '/storage/receipts/' . $file)) {
                        copy($workDir . '/storage/receipts/' . $file, $this->receiptsDir . '/' . $file);
                        $receiptsRestored++;
                    }
                }
            }

            $documentsRestored = 0;
            if (is_dir($workDir . '/storage/documents')) {
                Files::mkdir($this->documentsDir);
                foreach (scandir($workDir . '/storage/documents') as $file) {
                    if ($file !== '.' && $file !== '..' && is_file($workDir . '/storage/documents/' . $file)) {
                        copy($workDir . '/storage/documents/' . $file, $this->documentsDir . '/' . $file);
                        $documentsRestored++;
                    }
                }
            }

            // Protokolleintrag erstellen
            (new AuditLog($this->pdo()))->record('backup_restored', $userId, [
                'tables' => $verification['tables_count'],
                'receipts' => $receiptsRestored,
                'documents' => $documentsRestored,
                'version' => $verification['manifest']['version'] ?? 'unknown',
            ]);

            return [
                'tables_count' => $verification['tables_count'],
                'receipts_restored' => $receiptsRestored,
                'documents_restored' => $documentsRestored,
            ];
        } finally {
            $this->deleteDir($workDir);
        }
    }

    private function dumpDatabase(string $targetDir): void
    {
        $backup = new DatabaseBackup($this->pdo(), $targetDir);
        $progress = $backup->start();
        do {
            $progress = $backup->step($progress);
        } while (!($progress['done'] ?? false));
    }

    private function generateSqlDump(string $dbDir, string $targetSqlPath): void
    {
        $schema = Files::read($dbDir . '/schema.json');
        if (!is_array($schema)) {
            return;
        }

        $sql = "-- ====================================================================\n";
        $sql .= "-- Vereinskiste PostgreSQL Dump\n";
        $sql .= "-- Erstellt am: " . date('c') . "\n";
        $sql .= "-- Anwendungsversion: " . $this->version . "\n";
        $sql .= "-- ====================================================================\n\n";
        $sql .= "BEGIN;\n";
        $sql .= "SET LOCAL client_encoding = 'UTF8';\n";
        $sql .= "SET LOCAL standard_conforming_strings = on;\n\n";

        // Tabellen und Sequenzen anlegen
        if (isset($schema['create']) && is_array($schema['create'])) {
            foreach ($schema['create'] as $statement) {
                $sql .= $statement . ";\n";
            }
            $sql .= "\n";
        }

        // Tabellendaten einfügen
        if (isset($schema['tables']) && is_array($schema['tables'])) {
            foreach ($schema['tables'] as $i => $table) {
                $jsonlPath = $dbDir . '/' . $i . '.jsonl';
                if (!is_file($jsonlPath)) {
                    continue;
                }
                $lines = file($jsonlPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if (!$lines) {
                    continue;
                }
                $sql .= "-- Daten für Tabelle: " . $table['name'] . "\n";
                $cols = array_map([DatabaseSchema::class, 'quote'], $table['columns']);
                foreach ($lines as $line) {
                    $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    $values = [];
                    foreach ($table['columns'] as $col) {
                        $val = $row[$col] ?? null;
                        if ($val === null) {
                            $values[] = 'NULL';
                        } elseif (is_bool($val)) {
                            $values[] = $val ? 'TRUE' : 'FALSE';
                        } elseif (is_int($val) || is_float($val)) {
                            $values[] = (string) $val;
                        } else {
                            $values[] = $this->pdo()->quote((string) $val);
                        }
                    }
                    $sql .= 'INSERT INTO public.' . DatabaseSchema::quote($table['name']) . ' (' . implode(',', $cols)
                        . ') OVERRIDING SYSTEM VALUE VALUES (' . implode(',', $values) . ");\n";
                }
                $sql .= "\n";
            }
        }

        // Constraints, Trigger und Indizes
        if (isset($schema['post']) && is_array($schema['post'])) {
            foreach ($schema['post'] as $statement) {
                $sql .= $statement . ";\n";
            }
            $sql .= "\n";
        }

        // Sequenzwerte setzen
        if (isset($schema['sequences']) && is_array($schema['sequences'])) {
            foreach ($schema['sequences'] as $sequence) {
                $sql .= "SELECT setval('public." . DatabaseSchema::quote($sequence['sequencename']) . "', "
                    . (int) $sequence['last'] . ", " . ($sequence['called'] ? 'true' : 'false') . ");\n";
            }
            $sql .= "\n";
        }

        $sql .= "COMMIT;\n";
        file_put_contents($targetSqlPath, $sql);
    }

    private function getClubName(): string
    {
        try {
            $data = $this->scalar("SELECT data->>'name' FROM club_profile WHERE id = 1");
            if (is_string($data) && trim($data) !== '') {
                return trim($data);
            }
        } catch (Throwable) {
        }
        return 'Verein';
    }

    private function scalar(string $sql): mixed
    {
        try {
            return $this->pdo()->query($sql)->fetchColumn();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{count:int, bytes:int}
     */
    private function dirStats(string $dir): array
    {
        if (!is_dir($dir)) {
            return ['count' => 0, 'bytes' => 0];
        }
        $count = 0;
        $bytes = 0;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_file($path)) {
                $count++;
                $bytes += (int) filesize($path);
            }
        }
        return ['count' => $count, 'bytes' => $bytes];
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($source)) {
            return;
        }
        Files::mkdir($target);
        foreach (scandir($source) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $from = $source . '/' . $item;
            $to = $target . '/' . $item;
            if (is_file($from)) {
                copy($from, $to);
            }
        }
    }

    private function zipDirectory(string $sourceDir, string $zipFile): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ZIP-Datei konnte nicht erstellt werden: ' . $zipFile);
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $sourceDirLen = strlen(rtrim($sourceDir, '/\\')) + 1;
        foreach ($files as $file) {
            if ($file->isFile()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, $sourceDirLen);
                // Standardisierte Vorwärtsslashes im ZIP-Archiv
                $zipRelative = str_replace('\\', '/', $relativePath);
                $zip->addFile($filePath, $zipRelative);
            }
        }
        $zip->close();
    }

    private function createTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(8));
        Files::mkdir($dir);
        return $dir;
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }
        @rmdir($dir);
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0, ',', '.') . ' KB';
        }
        return $bytes . ' B';
    }
}
