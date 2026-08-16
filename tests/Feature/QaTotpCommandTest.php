<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class QaTotpCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_a_real_totp_for_a_seeded_testing_actor(): void
    {
        $this->seed(DatabaseSeeder::class);

        self::assertSame(0, Artisan::call('qa:totp', ['email' => 'qa.cajera@misvales.test']));
        $code = trim(Artisan::output());

        self::assertMatchesRegularExpression('/^\d{6}$/', $code);

        $secret = config('bootstrap.local_testing_totp_secret');
        if (is_string($secret) && $secret !== '') {
            self::assertTrue((new Google2FA)->verifyKey($secret, $code));
        }
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
