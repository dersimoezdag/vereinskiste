<?php
declare(strict_types=1);

// Router fuer den PHP-Entwicklungsserver (nur Tests): echtes HTTP, echte Set-Cookie-Header.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Vereinskiste\Http\Session\NativeSessionStore;

$store = new NativeSessionStore();
$store->configure(strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

switch ($_GET['a'] ?? '') {
    case 'start':
        $store->set('seen', 1);
        break;
    case 'login':
        $store->regenerate();
        $store->set('user_id', 7);
        break;
    case 'logout':
        $store->destroy();
        break;
}

header('Content-Type: text/plain');
echo json_encode(['user_id' => $_GET['a'] === 'logout' ? null : $store->get('user_id')]);
$store->commit();
