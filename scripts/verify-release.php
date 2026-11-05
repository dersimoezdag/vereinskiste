<?php
declare(strict_types=1);

$zip = new ZipArchive();
if ($argc !== 2 || $zip->open($argv[1]) !== true) {
    fwrite(STDERR, "Release-ZIP fehlt oder ist unlesbar.\n");
    exit(1);
}
foreach (['app/migrations/0027_integrations.sql', 'app/src/Integrations/Gateway.php', 'app/src/Integrations/McpController.php', 'app/templates/settings/integrations.twig', 'docs/api-mcp.md', 'app/migrations/0017_club_documents.sql', 'public/assets/vendor/tinymce/tinymce.min.js', 'public/assets/vendor/tinymce/license.md', 'public/assets/vendor/tinymce-source/tinymce-8.10.0.tar.gz', 'public/assets/vendor/tinymce/langs/de.js', 'app/vendor/dompdf/dompdf/src/Dompdf.php', 'app/vendor/ezyang/htmlpurifier/library/HTMLPurifier.php', 'app/vendor/autoload.php', 'app/bootstrap.php', 'app/VERSION', 'app/migrations/0001_initial.sql',
    'app/update-bootstrap.php','app/update-trust.json','app/src/Update/Coordinator.php','app/src/Text/de/auto_update.php','public/assets/auto-update.js',
    'app/migrations/0006_reset_request_queue.sql', 'app/migrations/0010_bank_import.sql',
    'app/src/Text/de/core.php', 'app/src/Text/de/foundation.php', 'app/src/Text/de/bank_import.php',
    'app/src/Text/de/updates.php', 'app/src/Text/de/dashboard.php', 'app/src/Install/GitHubTransport.php', 'app/src/Install/UpdateCheckFailure.php',
    'app/cron.php', 'app/migrations/0015_receipt_intake_payments.sql', 'public/assets/vendor/ocr/tesseract.min.js', 'public/assets/invoice-ocr.js', 'docs/hosting.md', 'docs/automatic-updates.md', 'docs/plans/ausbau/10-csv-import.md', 'app/config/', 'app/storage/',
    'public/index.php', 'public/.htaccess', 'public/assets/logo.svg', 'public/assets/logo-dark.svg', 'public/assets/app.css', 'public/assets/ui.js', 'public/assets/signature-pad.js', 'public/assets/application-stepper.js', 'public/assets/confetti.js', 'app/migrations/0023_split_house_numbers.sql', 'public/assets/bank-import.js', 'public/assets/dashboard.js', 'public/assets/theme.js',
    'public/assets/vendor/chartjs/chart.umd.min.js', 'public/assets/vendor/chartjs/LICENSE',
    'public/assets/vendor/material-symbols/symbols.svg', 'public/assets/vendor/material-symbols/LICENSE',
    'public/assets/vendor/fonts/geist-latin-wght-normal.woff2', 'public/assets/vendor/fonts/geist-latin-ext-wght-normal.woff2', 'public/assets/vendor/fonts/geist-LICENSE',
    'public/assets/vendor/fonts/geist-mono-latin-wght-normal.woff2', 'public/assets/vendor/fonts/geist-mono-latin-ext-wght-normal.woff2', 'public/assets/vendor/fonts/geist-mono-LICENSE', 'LICENSE', 'README.md'] as $name) {
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
// app.css wird gebaut: Sie muss Tailwind-Ausgabe sein und jede per @font-face eingebundene Schrift muss im Paket liegen.
$css = (string)$zip->getFromName('public/assets/app.css');
if (strlen($css) < 10000 || !str_contains($css, 'tailwindcss')) { throw new RuntimeException('public/assets/app.css ist leer oder kein Tailwind-Build.'); }
preg_match_all('~url\(\s*[\'"]?(vendor/fonts/[^\'")]+)~', $css, $fonts);
if ($fonts[1] === []) { throw new RuntimeException('public/assets/app.css bindet keine Schriften ein.'); }
foreach (array_unique($fonts[1]) as $font) {
    if ($zip->locateName('public/assets/' . $font) === false) { throw new RuntimeException('Schrift aus app.css fehlt: public/assets/' . $font); }
}
$zip->close();
echo "Release-Paket geprüft.\n";
