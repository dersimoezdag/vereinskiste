<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if ($argc !== 3 || !preg_match('/^\d+\.\d+\.\d+\z/', $argv[2])) { throw new RuntimeException('Usage: sign-release.php ZIP VERSION'); }
$root = dirname(__DIR__); $zip = $argv[1];
$encoded = getenv('VK_RELEASE_SIGNING_KEY');
if (!$encoded) { $encoded = @file_get_contents($root . '/.release-secrets/signing.key'); }
if (!$encoded) { throw new RuntimeException('Signierschlüssel fehlt. Stabile Releases werden nicht unsigniert erstellt.'); }
$key = base64_decode(trim($encoded), true);
if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) { throw new RuntimeException('Ungültiger Signierschlüssel.'); }
$trust = json_decode(file_get_contents($root . '/app/update-trust.json'), true, 16, JSON_THROW_ON_ERROR);
if (!hash_equals($trust['public_key'], base64_encode(sodium_crypto_sign_publickey_from_secretkey($key)))) { throw new RuntimeException('Signierschlüssel passt nicht zum Prüfschlüssel.'); }
$data = ['protocol' => 1,'version' => $argv[2],'package' => basename($zip),'size' => filesize($zip),
    'sha256' => hash_file('sha256', $zip),'php' => '8.4.0','key_id' => $trust['key_id']];
$manifest = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
file_put_contents($zip . '.manifest.json', $manifest);
file_put_contents($zip . '.manifest.sig', base64_encode(sodium_crypto_sign_detached($manifest, $key)) . "\n");
file_put_contents($zip . '.sha256', $data['sha256'] . '  ' . basename($zip) . "\n");
sodium_memzero($key); $encoded = null;
echo "Release-Manifest signiert.\n";
