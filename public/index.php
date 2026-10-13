<?php
declare(strict_types=1);

require __DIR__ . '/../app/update-bootstrap.php';
try {
    $instanceApp = dirname(__DIR__) . '/app';
    $runtime = new Vereinskiste\Update\Runtime($instanceApp);
    if ($runtime->serveAsset($_SERVER['REQUEST_URI'] ?? '/')) { exit; }
    define('VK_INSTANCE_APP', $instanceApp);
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $probe = preg_match('~^/_update-probe/([a-f0-9]{64})\z~', $path, $match) === 1 && $runtime->probeAllowed($match[1]);
    require $runtime->application($probe) . '/bootstrap.php';
} catch (Throwable) {
    http_response_code(503); header('Cache-Control: no-store'); echo 'Die Anwendung wird aktualisiert. Bitte später erneut versuchen.'; exit;
}

vk_create_app()->run();
