<?php

namespace Tests\Unit;

use App\Modules\Access\Application\MFA\PasskeyAttestationVerifier;
use App\Modules\Access\Application\MFA\RecoveryCodeGenerator;
use App\Modules\Access\Application\MFA\TotpVerifier;
use Illuminate\Support\Facades\Cache;
use OTPHP\TOTP;
use Tests\TestCase;

final class MfaServicesTest extends TestCase
{
    public function test_totp_accepts_one_adjacent_period(): void
    {
        $verifier = new TotpVerifier;
        $secret = $verifier->generateSecret('gerente@example.test')['secret'];
        $timestamp = 1_800_000_000;
        $totp = TOTP::create(secret: $secret, period: 30, digest: 'sha1', digits: 6);
        $previousPeriodCode = $totp->at($timestamp - 30);

        self::assertTrue($verifier->verifyAt($secret, $previousPeriodCode, $timestamp));
    }

    public function test_totp_rejects_reused_code_for_same_time_step(): void
    {
        $verifier = new TotpVerifier;
        $secret = $verifier->generateSecret('gerente@example.test')['secret'];
        $timestamp = 1_800_000_000;
        $totp = TOTP::create(secret: $secret, period: 30, digest: 'sha1', digits: 6);
        $code = $totp->at($timestamp);

        Cache::store((string) config('access.replay_cache_store'))->clear();

        self::assertTrue($verifier->verifyAt($secret, $code, $timestamp, 'credential-hash'));
        self::assertFalse($verifier->verifyAt($secret, $code, $timestamp, 'credential-hash'));
    }

    public function test_recovery_code_hash_is_keyed_and_stable(): void
    {
        $hash = RecoveryCodeGenerator::hashCode('abcd-1234-efab-5678');

        self::assertSame($hash, RecoveryCodeGenerator::hashCode(' ABCD-1234-EFAB-5678 '));
        self::assertNotSame(hash('sha256', 'ABCD-1234-EFAB-5678'), $hash);
    }

    public function test_passkey_verifier_fails_closed_until_webauthn_library_is_wired(): void
    {
        $verifier = new PasskeyAttestationVerifier('misvales.test', 'MisVales', 'https://misvales.test');
        $options = $verifier->generateCreationOptions('user-public-id', 'gerente@example.test', 'Gerente');

        self::assertSame('required', $options['userVerification']);
        self::assertFalse($verifier->verifyAssertion('credential', '{"kty":"EC"}', '{"signature":"x"}', 1));
        self::assertSame('https://misvales.test', $verifier->expectedOrigin());
    }
}
