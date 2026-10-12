<?php
declare(strict_types=1);

if ($argc !== 3 || !is_dir($argv[1])) {
    fwrite(STDERR, "Usage: package-release.php STAGING_DIRECTORY ZIP_FILE\n");
    exit(1);
}
$root = realpath($argv[1]);
$zip = new ZipArchive();
if ($zip->open($argv[2], ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('ZIP konnte nicht erstellt werden.');
}
foreach (['app/config', 'app/storage'] as $dir) { $zip->addEmptyDir($dir); }
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->isFile()) {
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
        if (preg_match('~(^|/)(\.git|\.github|tests)(/|$)~', $relative)) {
            continue;
        }
        $zip->addFile($file->getPathname(), $relative);
    }
}
$zip->close();
echo $argv[2] . "\n";
