<?php
declare(strict_types=1);

$zip = new ZipArchive();
if ($argc !== 2 || $zip->open($argv[1]) !== true) {
    fwrite(STDERR, "Release-ZIP fehlt oder ist unlesbar.\n");
    exit(1);
}
foreach (['app/vendor/autoload.php', 'app/bootstrap.php', 'app/migrations/0001_initial.sql',
    'app/migrations/0005_unique_account_category_names.sql', 'app/config/', 'app/storage/',
    'public/index.php', 'public/.htaccess', 'public/assets/app.css',
    'public/assets/vendor/tabler/tabler.min.css', 'public/assets/vendor/tabler/tabler.min.js', 'LICENSE', 'README.md'] as $name) {
    if ($zip->locateName($name) === false) { throw new RuntimeException('Release-Datei fehlt: ' . $name); }
}
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (preg_match('~(^|/)(\.git|\.github|\.phpunit\.cache|tests)(/|$)~', $name)
        || str_starts_with($name, 'app/vendor/phpunit/')
        || preg_match('~^app/config/.+~', $name) || preg_match('~^app/storage/.+~', $name)
        || in_array($name, ['Dockerfile.dev', 'docker-compose.dev.yml'], true)) {
        throw new RuntimeException('Unerlaubte Release-Datei: ' . $name);
    }
}
$zip->close();
echo "Release-Paket geprüft.\n";
