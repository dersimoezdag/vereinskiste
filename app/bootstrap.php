<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Vereinskiste\Http\Kernel;

/**
 * Baut die Slim-App aus app/config/config.php (falls vorhanden).
 */
function vk_create_app(): \Slim\App
{
    $instance = defined('VK_INSTANCE_APP') ? VK_INSTANCE_APP : __DIR__;
    $file = $instance . '/config/config.php';
    if (!is_file($file)) {
        return Kernel::createInstaller();
    }
    $config = is_file($file) ? require $file : [];
    return Kernel::create(['app_dir'=>$instance,'code_dir'=>__DIR__,'templates'=>__DIR__ . '/templates',
        'migration_dir'=>__DIR__ . '/migrations'] + (is_array($config) ? $config : []) + ['maintenance' => true]);
}
