<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MfaCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class QaTotpCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_a_real_totp_for_a_seeded_testing_actor(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        config()->set('bootstrap.local_testing_totp_secret', $secret);
        $user = User::factory()->create([
            'email' => 'qa.cajera@misvales.test',
            'normalized_email' => 'qa.cajera@misvales.test',
        ]);
        MfaCredential::query()->create([
            'user_id' => $user->id,
            'type' => 'TOTP',
            'label' => 'QA test',
            'confirmed_at' => now(),
            'secret_ciphertext' => Crypt::encryptString($secret),
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ]);

        self::assertSame(0, Artisan::call('qa:totp', ['email' => 'qa.cajera@misvales.test']));
        $code = trim(Artisan::output());

        self::assertMatchesRegularExpression('/^\d{6}$/', $code);

        self::assertTrue((new Google2FA)->verifyKey($secret, $code));
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        app()->detectEnvironment(fn (): string => 'production');

        try {
            self::assertSame(1, Artisan::call('qa:totp', ['email' => 'qa.cajera@misvales.test']));
        } finally {
            app()->detectEnvironment(fn (): string => 'testing');
        }
    }
}
