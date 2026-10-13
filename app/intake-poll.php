<?php
declare(strict_types=1);
// Launched only by cron in a bounded subprocess. Never expose credentials in arguments.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$instance = $argv[1] ?? __DIR__;
require __DIR__ . '/vendor/autoload.php';
try {
    $config = require $instance . '/config/config.php';
    $pdo = Vereinskiste\Db\Connection::fromConfig($config['db']);
    // Hold the shared update barrier for this independent connection as well.
    if (!(bool)$pdo->query('SELECT pg_try_advisory_lock_shared(' . Vereinskiste\Update\Coordinator::BARRIER . ')')->fetchColumn()) { exit(1); }
    if ((new Vereinskiste\Update\Runtime($instance))->maintenance()!==null || (new Vereinskiste\Install\Updater($pdo,__DIR__.'/migrations'))->needsUpdate()) { exit(1); }
    $settings = (new Vereinskiste\Intake\MailboxSettings($instance . '/config'))->get()['config'];
    (new Vereinskiste\Intake\MailIntake($pdo,$config['documents_dir']??$instance.'/storage/documents'))->poll($settings);
} catch (Throwable) { exit(1); }
