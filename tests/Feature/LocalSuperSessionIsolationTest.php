<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LocalSuperSessionIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_local_manager_login_is_not_replaced_by_the_technical_session(): void
    {
        $this->seed(DatabaseSeeder::class);
        app()->detectEnvironment(fn (): string => 'local');
        config()->set('bootstrap.local_super_session.enabled', false);

        try {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'test@gmail.com',
                'password' => '123456789ggg',
            ])
                ->assertOk()
                ->assertJsonStructure(['mfa_challenge_token'])
                ->assertJsonMissing(['message' => 'Sesión técnica local iniciada.']);

            self::assertFalse(DB::table('users')
                ->where('normalized_email', 'codex-local-session@invalid.test')
                ->exists());
        } finally {
            app()->detectEnvironment(fn (): string => 'testing');
        }
    }
}
