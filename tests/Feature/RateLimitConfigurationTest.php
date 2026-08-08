<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RateLimitConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rate_limit_can_be_disabled_for_local_development(): void
    {
        config(['ratelimit.enabled' => false]);

        $email = Str::uuid()->toString().'@example.test';

        for ($attempt = 1; $attempt <= 7; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $email,
                'password' => 'invalid-password',
            ])->assertUnauthorized();
        }

        for ($attempt = 1; $attempt <= 12; $attempt++) {
            $this->postJson('/api/v1/auth/invitations/inspect', [])
                ->assertUnprocessable();
        }
    }
}
