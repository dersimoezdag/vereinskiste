<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$dsn = getenv('VK_TEST_DSN');
if (!is_string($dsn) || !preg_match('/dbname=vk_browser_[a-f0-9]+\z/', $dsn)) {
    throw new RuntimeException('Nur isolierte Browser-Testdatenbanken erlaubt.');
}
$directory = '/work/dist/preview';
if (!is_dir($directory)) { mkdir($directory, 0700, true); }
$pdo = Vereinskiste\Db\Connection::fromConfig(['dsn' => $dsn, 'user' => 'vk', 'pass' => 'vk']);
(new Vereinskiste\Db\Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
$hash = password_hash('preview-test-only-123', PASSWORD_DEFAULT);
$user = (new Vereinskiste\Auth\UserRepository($pdo))->create('Testvorstand', 'preview@example.org', $hash, ['vorstand'], null);
$account = (new Vereinskiste\Ledger\AccountRepository($pdo))->create('Barkasse', 'cash', '100.00', '2026-01-01', $user);
(new Vereinskiste\Ledger\BookingService($pdo))->create(['account_id' => (string) $account, 'booking_date' => '2026-10-12',
    'amount' => '42,50', 'direction' => 'in', 'description' => 'Spende für die Jugendarbeit und das gemeinsame Herbstfest'], $user);
$store = new Vereinskiste\Http\Session\ArraySessionStore();
$store->set('user_id', $user);
$store->set('credential_hash', hash('sha256', $hash));
$app = Vereinskiste\Http\Kernel::create(['pdo' => $pdo, 'session_store' => $store, 'require_https' => false]);
$factory = new Slim\Psr7\Factory\ServerRequestFactory();
$render = static function (string $name, string $path) use (&$app, $factory, $directory): void {
    $response = $app->handle($factory->createServerRequest('GET', $path));
    if ($response->getStatusCode() !== 200) { throw new RuntimeException('Vorschau konnte nicht gerendert werden.'); }
    // Gleiche CSP wie im HTTP-Betrieb, auch bei der lokalen Dateivorschau.
    $meta = '<meta http-equiv="Content-Security-Policy" content="'
        . htmlspecialchars($response->getHeaderLine('Content-Security-Policy'), ENT_QUOTES) . '">';
    $html = str_replace('<head>', '<head>' . $meta, (string) $response->getBody());
    file_put_contents($directory . '/' . $name . '.html', str_replace('/assets/', '../../public/assets/', $html));
};
foreach (['bookings' => '/bookings', 'booking-form' => '/bookings/new', 'reports' => '/reports/summary'] as $name => $path) {
    $render($name, $path);
}
$private = $directory . '/installer-app';
foreach (['config', 'storage', 'migrations'] as $folder) {
    if (!is_dir($private . '/' . $folder)) { mkdir($private . '/' . $folder, 0700, true); }
}
file_put_contents($private . '/config/setup.key', str_repeat('preview_test_only_', 3));
$store = new Vereinskiste\Http\Session\ArraySessionStore();
$app = Vereinskiste\Http\Kernel::createInstaller(['app_dir' => $private, 'session_store' => $store, 'require_https' => false]);
$render('setup-key', '/install');
$store->set('setup_proof', hash('sha256', str_repeat('preview_test_only_', 3)));
$render('install', '/install');
unlink($private . '/config/setup.key');
echo "Browser-Vorschau mit synthetischen Daten gerendert.\n";
