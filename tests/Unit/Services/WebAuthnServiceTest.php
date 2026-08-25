<?php

namespace Tests\Unit\Services;

use App\Services\Auth\WebAuthnService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class WebAuthnServiceTest extends TestCase
{
    private WebAuthnService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('webauthn.origins', [
            'https://safeacces.lat',
            'https://vpn.safeacces.lat',
        ]);

        $this->service = new WebAuthnService;
    }

    public function test_accepts_the_public_and_vpn_origins(): void
    {
        $this->service->assertOriginAllowed('https://safeacces.lat');
        $this->service->assertOriginAllowed('https://vpn.safeacces.lat');

        $this->addToAssertionCount(2);
    }

    public function test_rejects_an_origin_outside_the_explicit_allowlist(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid origin.');

        $this->service->assertOriginAllowed('https://attacker.example');
    }
}
