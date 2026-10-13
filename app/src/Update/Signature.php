<?php
declare(strict_types=1);
namespace Vereinskiste\Update;

/** Trust always comes from the original installation, never from the downloaded package. */
final class Signature
{
    public function __construct(private array $trust) {}
    public function verify(string $manifest, string $encodedSignature, string $expectedVersion): array
    {
        if (!extension_loaded('sodium')) { throw new Failure('requirements'); }
        if (strlen($manifest) > 16384 || strlen($encodedSignature) > 256) { throw new Failure('signature'); }
        $signature = base64_decode(trim($encodedSignature), true);
        $public = base64_decode($this->trust['public_key'] ?? '', true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || $public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !sodium_crypto_sign_verify_detached($signature, $manifest, $public)) { throw new Failure('signature'); }
        $data = json_decode($manifest, true, 16, JSON_THROW_ON_ERROR);
        if (($data['key_id'] ?? null) !== ($this->trust['key_id'] ?? null) || ($data['version'] ?? null) !== $expectedVersion
            || !preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\z/', $expectedVersion)
            || ($data['protocol'] ?? null) !== 1 || ($data['package'] ?? null) !== 'vereinskiste-' . $expectedVersion . '.zip'
            || !is_int($data['size'] ?? null) || $data['size'] < 1 || $data['size'] > 67108864
            || !preg_match('/^[a-f0-9]{64}\z/', $data['sha256'] ?? '')
            || !is_string($data['php'] ?? null) || !preg_match('/^8\.\d+\.\d+\z/', $data['php'])
            || version_compare(PHP_VERSION, $data['php'], '<')) { throw new Failure('manifest'); }
        return $data;
    }
}
