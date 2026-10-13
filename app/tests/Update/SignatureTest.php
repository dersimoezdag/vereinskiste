<?php
declare(strict_types=1);
namespace Vereinskiste\Tests\Update;

use PHPUnit\Framework\TestCase;
use Vereinskiste\Update\Failure;
use Vereinskiste\Update\Signature;

final class SignatureTest extends TestCase
{
    public function test_only_the_pinned_key_and_exact_signed_manifest_are_accepted(): void
    {
        $pair=sodium_crypto_sign_keypair(); $secret=sodium_crypto_sign_secretkey($pair);
        $trust=['key_id'=>'test','public_key'=>base64_encode(sodium_crypto_sign_publickey($pair))];
        $manifest=json_encode(['key_id'=>'test','version'=>'1.2.3','package'=>'vereinskiste-1.2.3.zip',
            'protocol'=>1,'size'=>100,'sha256'=>str_repeat('a',64),'php'=>'8.4.0'],JSON_THROW_ON_ERROR);
        $signature=base64_encode(sodium_crypto_sign_detached($manifest,$secret));
        $this->assertSame('1.2.3',(new Signature($trust))->verify($manifest,$signature,'1.2.3')['version']);
        foreach ([$manifest . ' ',str_replace('1.2.3','1.2.4',$manifest)] as $tampered) {
            try { (new Signature($trust))->verify($tampered,$signature,'1.2.3'); $this->fail('Manipulation'); }
            catch (Failure $e) { $this->assertSame('signature',$e->reason); }
        }
        $other=sodium_crypto_sign_keypair();
        $this->expectException(Failure::class);
        (new Signature(['key_id'=>'test','public_key'=>base64_encode(sodium_crypto_sign_publickey($other))]))->verify($manifest,$signature,'1.2.3');
    }
    public function test_even_authentic_manifest_for_a_different_target_is_rejected(): void
    {
        $pair=sodium_crypto_sign_keypair(); $body='{"version":"9.0.0"}';
        $this->expectException(Failure::class);
        (new Signature(['key_id'=>'test','public_key'=>base64_encode(sodium_crypto_sign_publickey($pair))]))
            ->verify($body,base64_encode(sodium_crypto_sign_detached($body,sodium_crypto_sign_secretkey($pair))),'1.2.3');
    }
}
