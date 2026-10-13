<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || $argc!==3) { exit(1); }
require dirname(__DIR__) . '/app/vendor/autoload.php';
$trust=json_decode(file_get_contents(dirname(__DIR__) . '/app/update-trust.json'),true,16,JSON_THROW_ON_ERROR);
$manifest=(new Vereinskiste\Update\Signature($trust))->verify(file_get_contents($argv[1] . '.manifest.json'),file_get_contents($argv[1] . '.manifest.sig'),$argv[2]);
if (filesize($argv[1])!==$manifest['size'] || !hash_equals($manifest['sha256'],hash_file('sha256',$argv[1]))) { throw new RuntimeException('Signiertes Paket stimmt nicht zum Manifest.'); }
(new Vereinskiste\Update\Archive())->inspect($argv[1],$argv[2]);
echo "Signatur, Paket-Hash und sichere ZIP-Struktur geprüft.\n";
