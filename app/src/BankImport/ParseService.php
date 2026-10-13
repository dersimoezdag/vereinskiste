<?php
declare(strict_types=1);
namespace Vereinskiste\BankImport;

use PDO;
use Vereinskiste\BankImport\Parsers\CsvEntry;
use Vereinskiste\BankImport\Parsers\CsvMapping;
use Vereinskiste\BankImport\Parsers\CsvReader;
use Vereinskiste\Files\DocumentStore;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Jobs\Queue;
use Vereinskiste\Ledger\Transaction;
use Vereinskiste\Ledger\ValidationError;

final class ParseService
{
    public function __construct(private PDO $db, private string $directory) {}
    public function configure(int $id, int $version, array $input, int $actor): void
    {
        Transaction::run($this->db, function () use ($id, $version, $input, $actor): void {
            $import = (new Repository($this->db))->find($id, true);
            if (!$import || (int) $import['version'] !== $version || $import['status'] !== 'uploaded') { throw new Conflict(); }
            $settings = $import['settings'] + CsvMapping::read($input, count($import['settings']['headers']));
            $stmt = $this->db->prepare("UPDATE bank_imports SET settings=?::jsonb,status='parsing',version=version+1 WHERE id=?");
            $stmt->execute([json_encode($settings, JSON_THROW_ON_ERROR), $id]);
            $name = Fields::read($input, ['profile_name' => 100])['profile_name'];
            if ($name !== '') {
                $profile = array_diff_key($settings, array_flip(['preview','total_rows']));
                $this->db->prepare('INSERT INTO bank_csv_profiles(name,settings,created_by) VALUES (?,?::jsonb,?)')
                    ->execute([$name, json_encode($profile, JSON_THROW_ON_ERROR), $actor]);
            }
            $this->schedule($id, (int) $import['parse_revision'], 0);
        });
    }
    public function step(int $id): void
    {
        $repo = new Repository($this->db); $import = $repo->find($id);
        if (!$import || $import['status'] !== 'parsing') { return; }
        $file = (new DocumentStore($this->db, ['documents' => $this->directory]))->open((int) $import['file_id']);
        if (!$file || hash_file('sha256', $file['path']) !== $import['sha256']) { $this->fail($id, 'bank.file.missing', (int) $import['parse_revision']); return; }
        $batch = [];
        try {
            foreach ((new CsvReader())->rows($file['path'], $import['settings']) as $number => $raw) {
                if ($number <= (int) $import['parsed_rows']) { continue; }
                $batch[$number] = (new CsvEntry())->read($raw, $import['settings'], $import);
                if (count($batch) === 250) { break; }
            }
        } catch (ValidationError) { $this->fail($id, 'bank.file.invalid', (int) $import['parse_revision']); return; }
        Transaction::run($this->db, function () use ($id, $import, $batch, $repo): void {
            $current = $repo->find($id, true);
            if ($current['status'] !== 'parsing' || (int) $current['parsed_rows'] !== (int) $import['parsed_rows']
                || $current['parse_revision'] !== $import['parse_revision']) { return; }
            foreach ($batch as $number => $entry) {
                $this->db->prepare('INSERT INTO bank_import_rows(import_id,row_number,proposal,issues) VALUES (?,?,?::jsonb,?::jsonb)')
                    ->execute([$id, $number, json_encode($entry['proposal'], JSON_THROW_ON_ERROR), json_encode($entry['issues'], JSON_THROW_ON_ERROR)]);
            }
            $parsed = (int) $import['parsed_rows'] + count($batch);
            if (count($batch) === 0 && $parsed < (int) $import['total_rows']) { $this->fail($id, 'bank.file.invalid', (int) $import['parse_revision']); return; }
            $status = $parsed === (int) $import['total_rows'] ? 'review' : 'parsing';
            $this->db->prepare('UPDATE bank_imports SET parsed_rows=?,status=?,version=version+1 WHERE id=?')->execute([$parsed, $status, $id]);
            if ($status === 'parsing') { $this->schedule($id, (int) $import['parse_revision'], $parsed); }
        });
    }
    private function schedule(int $id, int $revision, int $offset): void { (new Queue($this->db))->enqueue('bank_csv_parse', ['id' => $id], $id . ':' . $revision . ':' . $offset, 20); }
    private function fail(int $id, string $code, int $revision): void
    {
        $this->db->prepare("UPDATE bank_imports SET status='failed',error_code=?,version=version+1 WHERE id=? AND parse_revision=? AND status='parsing'")->execute([$code, $id, $revision]);
    }
}
