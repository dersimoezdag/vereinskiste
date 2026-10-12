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
    $service = new Vereinskiste\Auth\PasswordResetService(
        static fn (): PDO => $pdo, new Vereinskiste\Mail\Mailer($config['smtp'] ?? []),
        $config['base_url'] ?? '', require __DIR__ . '/src/Text/de.php'
    );
    $service->processQueue();
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
