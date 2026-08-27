<?php

namespace Tests\Unit\Services;

use App\Services\Security\VpnContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class VpnContextTest extends TestCase
{
    public function test_it_recognizes_an_address_inside_the_configured_vpn(): void
    {
        Config::set('vpn.networks', ['192.168.50.0/24']);
        $request = Request::create('/api/v1/me', server: ['REMOTE_ADDR' => '192.168.50.41']);

        $this->assertTrue(app(VpnContext::class)->resolve($request));
    }

    public function test_it_rejects_an_address_outside_the_configured_vpn(): void
    {
        Config::set('vpn.networks', ['192.168.50.0/24']);
        $request = Request::create('/api/v1/me', server: ['REMOTE_ADDR' => '192.168.51.41']);

        $this->assertFalse(app(VpnContext::class)->resolve($request));
    }

    public function test_it_fails_closed_when_no_vpn_network_is_configured(): void
    {
        Config::set('vpn.networks', []);
        $request = Request::create('/api/v1/me', server: ['REMOTE_ADDR' => '192.168.50.41']);

        $this->assertFalse(app(VpnContext::class)->resolve($request));
    }
}
