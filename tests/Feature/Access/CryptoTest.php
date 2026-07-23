<?php
namespace Tests\Feature\Access;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;
class CryptoTest extends TestCase {
    public function test_crypto() {
        $encrypted = Crypt::encryptString('hello');
        $decrypted = Crypt::decryptString($encrypted);
        $this->assertEquals('hello', $decrypted);
    }
}
