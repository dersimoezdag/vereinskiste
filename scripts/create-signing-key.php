<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__);
$privateFile = $root . '/.release-secrets/signing.key'; $publicFile = $root . '/app/update-trust.json';
if (is_file($privateFile) || is_file($publicFile)) { throw new RuntimeException('Vorhandene Signieridentität wird nicht überschrieben.'); }
if (!extension_loaded('sodium')) { throw new RuntimeException('ext-sodium fehlt.'); }
if (!is_dir(dirname($privateFile))) { mkdir(dirname($privateFile), 0700, true); }
$pair = sodium_crypto_sign_keypair(); $secret = sodium_crypto_sign_secretkey($pair);
file_put_contents($privateFile, base64_encode($secret) . "\n", LOCK_EX); chmod($privateFile, 0600);
$trust = ['key_id' => 'ed25519-' . substr(hash('sha256', sodium_crypto_sign_publickey($pair)), 0, 16),
    'public_key' => base64_encode(sodium_crypto_sign_publickey($pair))];
file_put_contents($publicFile, json_encode($trust, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
sodium_memzero($secret); sodium_memzero($pair);
echo "Signieridentität erstellt. Privater Schlüssel nur in .release-secrets; öffentlicher Prüfschlüssel in app/update-trust.json.\n";
