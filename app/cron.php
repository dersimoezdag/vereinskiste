<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config/config.php';
$directory = __DIR__ . '/storage/security';
if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
    throw new RuntimeException('Cron-Speicher nicht verfuegbar.');
}
$lock = fopen($directory . '/mail-worker.lock', 'c');
if ($lock === false) { throw new RuntimeException('Cron-Sperre nicht verfuegbar.'); }
chmod($directory . '/mail-worker.lock', 0600);
if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); exit; }
try {
    $pdo = Vereinskiste\Db\Connection::fromConfig($config['db']);
    // Vor einem Schema-Update keine Nachrichten zustellen.
    if ((new Vereinskiste\Install\Updater($pdo, __DIR__ . '/migrations'))->needsUpdate()) {
        throw new RuntimeException('Bitte zuerst Datenbank aktualisieren.');
    }
    $smtp = $config['smtp'] ?? [];
    $smtp['from_name'] = (new Vereinskiste\Club\Profile($pdo))->name();
    $service = new Vereinskiste\Auth\PasswordResetService(
        static fn (): PDO => $pdo, new Vereinskiste\Mail\Mailer($smtp),
        $config['base_url'] ?? '', require __DIR__ . '/src/Text/de.php'
    );
    $queue = new Vereinskiste\Jobs\Queue($pdo);
    $slot = (string) intdiv(time(), 60);
    for ($mailSlot = 0; $mailSlot < 10; $mailSlot++) {
        $queue->enqueue('password_reset_mail', [], $slot . ':' . $mailSlot, 100);
    }
    $queue->enqueue('receipt_registration', [], $slot, 10);
    $queue->enqueue('upload_cleanup', [], (string) intdiv(time(), 86400), -10);
    (new Vereinskiste\Jobs\Runner($queue, [
        'password_reset_mail' => static function () use ($service): void { $service->processQueue(1); },
        'receipt_registration' => static function () use ($pdo): void {
            (new Vereinskiste\Files\StoredFileRepository($pdo))->registerReceipts(50);
        },
        'bank_csv_parse' => static function (array $payload) use ($pdo): void {
            (new Vereinskiste\BankImport\ParseService($pdo, __DIR__ . '/storage/documents'))->step((int) $payload['id']);
        },
        'upload_cleanup' => static function () use ($pdo): void {
            (new Vereinskiste\Files\UnregisteredUploads($pdo, __DIR__ . '/storage/documents'))->clean();
        },
    ]))->run(20, 20);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
