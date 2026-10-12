<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Vereinskiste\Http\Kernel;

/**
 * Baut die Slim-App aus app/config/config.php (falls vorhanden).
 */
function vk_create_app(): \Slim\App
{
    $file = __DIR__ . '/config/config.php';
    $config = is_file($file) ? require $file : [];
    return Kernel::create(is_array($config) ? $config : []);
}
