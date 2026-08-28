<?php

namespace Tests\Unit\Security;

use App\Services\Security\VpnContext;
use Illuminate\Http\Request;
use Tests\TestCase;

final class VpnContextTest extends TestCase
{
    public function test_local_vpn_simulation_header_distinguishes_the_second_frontend(): void
    {
        config()->set('vpn.networks', []);
        config()->set('vpn.hosts', []);
        $normal = Request::create('/api/v1/me', 'GET', server: ['REMOTE_ADDR' => '203.0.113.10']);
        $simulated = Request::create('/api/v1/me', 'GET', server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_MISVALES_VPN_SIMULATED' => 'true',
        ]);

        self::assertFalse(app(VpnContext::class)->resolve($normal));
        self::assertTrue(app(VpnContext::class)->resolve($simulated));
    }
}
