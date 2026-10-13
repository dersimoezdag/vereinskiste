<?php
declare(strict_types=1);

// Ausschliesslich fuer isolierte Entwicklungsdatenbanken, nicht im Release.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
[$script, $operation, $directory] = $argv;
$dsn = getenv('VK_TEST_DSN');
if (!is_string($dsn) || !preg_match('/dbname=vk_restore_[a-f0-9]+_(source|restored)\z/', $dsn)) {
    throw new RuntimeException('Nur isolierte Restore-Testdatenbanken erlaubt.');
}
$pdo = Vereinskiste\Db\Connection::fromConfig(['dsn' => $dsn, 'user' => 'vk', 'pass' => 'vk']);
if ($operation === 'seed') {
    (new Vereinskiste\Db\Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
    mkdir($directory . '/source/receipts', 0700, true);
    $user = (new Vereinskiste\Auth\UserRepository($pdo))->create('Restore Test', 'restore@example.org',
        password_hash('restore-test-only-123', PASSWORD_DEFAULT), ['vorstand'], null);
    $pdo->exec("INSERT INTO accounts (name, type, opening_balance, opening_date) VALUES ('Kasse', 'cash', 25, '2026-01-01')");
    $pdo->exec("INSERT INTO accounts (name, type, opening_date) VALUES ('Bank', 'bank', '2026-01-01')");
    file_put_contents($directory . '/bank.csv', "Datum;Betrag\n02.01.2026;12,50\n");
    $importId = (new Vereinskiste\BankImport\Upload($pdo, $directory . '/source/documents'))->create(
        2, $directory . '/bank.csv', 'bank.csv', $user);
    $parser = new Vereinskiste\BankImport\ParseService($pdo, $directory . '/source/documents');
    $parser->configure($importId, 1, ['booking_date' => '0','amount' => '1','date_format' => 'd.m.Y',
        'decimal_format' => 'comma','account_checked' => '1'], $user);
    $parser->step($importId);
    $import = (new Vereinskiste\BankImport\Repository($pdo))->find($importId);
    (new Vereinskiste\BankImport\ConfirmService($pdo))->step($importId, (int) $import['version'], $user);
    $service = new Vereinskiste\Ledger\BookingService($pdo);
    $booking = $service->create(['account_id' => '1', 'booking_date' => '2026-10-01',
        'amount' => '12,50', 'direction' => 'in', 'description' => 'Restore Spende'], $user);
    $updated = $service->update((int) $booking['id'], ['description' => 'Restore Verlauf'], 'Teständerung', 1, $user);
    file_put_contents($directory . '/receipt.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
    (new Vereinskiste\Receipts\ReceiptStore($pdo, $directory . '/source/receipts'))->add(
        (int) $updated['id'], $directory . '/receipt.pdf', 'restore.pdf', $user);
    file_put_contents($directory . '/source/config.php', "<?php return ['base_url' => 'https://restore.example.org'];\n");
} elseif ($operation === 'check') {
    mkdir($directory . '/restored', 0700, true);
    copy($directory . '/source/config.php', $directory . '/restored/config.php');
    foreach (['receipts','documents'] as $root) {
        mkdir($directory . '/restored/' . $root, 0700, true);
        foreach (glob($directory . '/source/' . $root . '/*') as $file) {
            copy($file, $directory . '/restored/' . $root . '/' . basename($file));
        }
    }
} else { throw new RuntimeException('Unbekannte Probe.'); }
$tables = ['users', 'user_roles', 'accounts', 'categories', 'bookings', 'booking_changes',
    'receipts', 'audit_log', 'settings', 'schema_migrations', 'password_resets', 'password_reset_requests',
    'club_profile','finance_contacts','foundation_changes','stored_files','jobs','bank_csv_profiles',
    'bank_imports','bank_import_rows','bank_import_attempts','bank_statements','bank_transactions'];
$snapshot = [];
foreach ($tables as $table) { $snapshot[$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll(); }
$snapshot['balance'] = (new Vereinskiste\Ledger\AccountRepository($pdo))->balance(1);
$snapshot['files'] = [];
$tree = $directory . '/' . ($operation === 'seed' ? 'source' : 'restored');
foreach ($snapshot['stored_files'] as $file) {
    $hash = hash_file('sha256', $tree . '/' . $file['root'] . '/' . $file['storage_name']);
    if ($hash !== trim($file['sha256'])) { throw new RuntimeException('Originaldatei beschädigt'); }
    $snapshot['files'][$file['root'] . '/' . $file['storage_name']] = $hash;
}
$snapshot['config'] = require $tree . '/config.php';
$fingerprint = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
if ($operation === 'seed') { file_put_contents($directory . '/fingerprint', $fingerprint); exit; }
if (!hash_equals(file_get_contents($directory . '/fingerprint'), $fingerprint)) {
    throw new RuntimeException('Wiederhergestellter Datenbestand weicht ab.');
}
$auth = new Vereinskiste\Auth\AuthService(new Vereinskiste\Auth\UserRepository($pdo),
    new Vereinskiste\Http\Session\ArraySessionStore(), null, new Vereinskiste\Auth\RateLimiter($directory . '/security'));
if ($auth->login('restore@example.org', 'restore-test-only-123') === null || !$auth->can('settings.manage')) {
    throw new RuntimeException('Wiederhergestellte Anmeldung/Rolle funktioniert nicht.');
}
echo "Restore geprüft: Tabellen, Verlauf, Saldo, Konfiguration, Beleg-SHA256 und Anmeldung/Rolle stimmen.\n";
