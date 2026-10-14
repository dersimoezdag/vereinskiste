<?php
declare(strict_types=1);

$zip = new ZipArchive();
if ($argc !== 2 || $zip->open($argv[1]) !== true) {
    fwrite(STDERR, "Release-ZIP fehlt oder ist unlesbar.\n");
    exit(1);
}
foreach (['app/migrations/0017_club_documents.sql', 'public/assets/vendor/tinymce/tinymce.min.js', 'public/assets/vendor/tinymce/license.md', 'public/assets/vendor/tinymce-source/tinymce-8.10.0.tar.gz', 'public/assets/vendor/tinymce/langs/de.js', 'app/vendor/dompdf/dompdf/src/Dompdf.php', 'app/vendor/ezyang/htmlpurifier/library/HTMLPurifier.php', 'app/vendor/autoload.php', 'app/bootstrap.php', 'app/VERSION', 'app/migrations/0001_initial.sql',
    'app/update-bootstrap.php','app/update-trust.json','app/src/Update/Coordinator.php','app/src/Text/de/auto_update.php','public/assets/auto-update.js',
    'app/migrations/0006_reset_request_queue.sql', 'app/migrations/0010_bank_import.sql',
    'app/src/Text/de/core.php', 'app/src/Text/de/foundation.php', 'app/src/Text/de/bank_import.php',
    'app/src/Text/de/updates.php', 'app/src/Text/de/dashboard.php', 'app/src/Install/GitHubTransport.php', 'app/src/Install/UpdateCheckFailure.php',
    'app/cron.php', 'app/migrations/0015_receipt_intake_payments.sql', 'public/assets/vendor/ocr/tesseract.min.js', 'public/assets/invoice-ocr.js', 'docs/hosting.md', 'docs/automatic-updates.md', 'docs/plans/ausbau/10-csv-import.md', 'app/config/', 'app/storage/',
    'public/index.php', 'public/.htaccess', 'public/assets/logo.svg', 'public/assets/logo-dark.svg', 'public/assets/app.css', 'public/assets/user-card.css', 'public/assets/bank-import.js', 'public/assets/dashboard.js', 'public/assets/theme.js',
    'public/assets/vendor/chartjs/chart.umd.min.js', 'public/assets/vendor/chartjs/LICENSE',
    'public/assets/vendor/material-symbols/symbols.svg', 'public/assets/vendor/material-symbols/LICENSE',
    'public/assets/vendor/tabler/tabler.min.css', 'public/assets/vendor/tabler/tabler.min.js', 'LICENSE', 'README.md'] as $name) {
    if ($zip->locateName($name) === false) { throw new RuntimeException('Release-Datei fehlt: ' . $name); }
}
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (preg_match('~(^|/)(\.git|\.github|\.phpunit\.cache|\.release-secrets|tests)(/|$)~', $name)
        || basename($name)==='signing.key'
        || str_starts_with($name, 'app/vendor/phpunit/')
        || preg_match('~^app/config/.+~', $name) || preg_match('~^app/storage/.+~', $name)
        || in_array($name, ['Dockerfile.dev', 'docker-compose.dev.yml'], true)) {
        throw new RuntimeException('Unerlaubte Release-Datei: ' . $name);
    }
}
$zip->close();
echo "Release-Paket geprüft.\n";
