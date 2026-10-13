<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (!defined('VK_INSTANCE_APP')) {
    require __DIR__ . '/update-bootstrap.php';
    define('VK_INSTANCE_APP',__DIR__);
    $cronRuntime=new Vereinskiste\Update\Runtime(__DIR__);
    $cronCode=$cronRuntime->application();
    if ($cronCode!==__DIR__) { require $cronCode . '/cron.php'; exit; }
}
require __DIR__ . '/vendor/autoload.php';
$instance=VK_INSTANCE_APP;
$config = require $instance . '/config/config.php';
$directory = $instance . '/storage/security';
if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
    throw new RuntimeException('Cron-Speicher nicht verfuegbar.');
}
$lock = fopen($directory . '/mail-worker.lock', 'c');
if ($lock === false) { throw new RuntimeException('Cron-Sperre nicht verfuegbar.'); }
chmod($directory . '/mail-worker.lock', 0600);
if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); exit; }
try {
    $cronStarted=microtime(true);
    $pdo = Vereinskiste\Db\Connection::fromConfig($config['db']);
    $cronRuntime=new Vereinskiste\Update\Runtime($instance);
    $updateState=(new Vereinskiste\Update\State($cronRuntime))->load();
    if ($updateState!==null && !in_array($updateState['phase'],[...Vereinskiste\Update\Coordinator::TERMINAL,'recovery_required'],true)) {
        (new Vereinskiste\Update\Coordinator($pdo,$cronRuntime))->step();
    }
    if ($cronRuntime->maintenance()!==null) { return; }
    $workerShared=(bool)$pdo->query('SELECT pg_try_advisory_lock_shared(' . Vereinskiste\Update\Coordinator::BARRIER . ')')->fetchColumn();
    if (!$workerShared || $cronRuntime->maintenance()!==null) { return; }
    // Vor einem Schema-Update keine Nachrichten zustellen.
    if ((new Vereinskiste\Install\Updater($pdo, __DIR__ . '/migrations'))->needsUpdate()) {
        throw new RuntimeException('Bitte zuerst Datenbank aktualisieren.');
    }
    $smtp = (new Vereinskiste\Mail\MailAccounts($instance . '/config', $config['smtp'] ?? []))->configuration();
    if (($smtp['from_name'] ?? '') === '') { $smtp['from_name'] = (new Vereinskiste\Club\Profile($pdo))->name(); }
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
            (new Vereinskiste\BankImport\ParseService($pdo, VK_INSTANCE_APP . '/storage/documents'))->step((int) $payload['id']);
        },
        'upload_cleanup' => static function () use ($pdo): void {
            (new Vereinskiste\Files\UnregisteredUploads($pdo, VK_INSTANCE_APP . '/storage/documents'))->clean();
        },
    ]))->run(max(1,20-(int)ceil(microtime(true)-$cronStarted)), 20);
} finally {
    if (($workerShared??false) && isset($pdo)) { $pdo->query('SELECT pg_advisory_unlock_shared(' . Vereinskiste\Update\Coordinator::BARRIER . ')'); }
    flock($lock, LOCK_UN);
    fclose($lock);
}
