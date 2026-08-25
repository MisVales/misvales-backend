<?php

namespace Tests\Unit\Http\Middleware;

use App\Exceptions\ApiException;
use App\Http\Middleware\RequireVpn;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class RequireVpnTest extends TestCase
{
    public function test_manager_mutation_is_allowed_inside_vpn(): void
    {
        $response = $this->middlewareResponse('POST', '192.168.50.20', true);

        $this->assertSame(204, $response->getStatusCode());
    }

    public function test_manager_mutation_is_rejected_outside_vpn(): void
    {
        try {
            $this->middlewareResponse('POST', '203.0.113.20', true);
            $this->fail('VPN_REQUIRED was not thrown.');
        } catch (ApiException $exception) {
            $this->assertSame('VPN_REQUIRED', $exception->errorCode);
            $this->assertSame(403, $exception->httpStatus);
        }
    }

    public function test_manager_read_is_allowed_outside_vpn(): void
    {
        $response = $this->middlewareResponse('GET', '203.0.113.20', true);

        $this->assertSame(204, $response->getStatusCode());
    }

    public function test_non_manager_mutation_keeps_existing_authorization_path(): void
    {
        $response = $this->middlewareResponse('POST', '203.0.113.20', false);

        $this->assertSame(204, $response->getStatusCode());
    }

    public function test_always_mode_rejects_reads_outside_vpn(): void
    {
        $this->expectException(ApiException::class);

        $this->middlewareResponse('GET', '203.0.113.20', false, 'always');
    }

    public function test_the_same_manager_session_is_re_evaluated_on_every_request(): void
    {
        $this->assertSame(204, $this->middlewareResponse('POST', '192.168.50.20', true)->getStatusCode());

        try {
            $this->middlewareResponse('POST', '203.0.113.20', true);
            $this->fail('VPN_REQUIRED was not thrown after leaving the VPN.');
        } catch (ApiException $exception) {
            $this->assertSame('VPN_REQUIRED', $exception->errorCode);
        }

        $this->assertSame(204, $this->middlewareResponse('POST', '192.168.50.20', true)->getStatusCode());
    }

    private function middlewareResponse(string $method, string $ip, bool $manager, string $mode = 'manager-write'): Response
    {
        Config::set('vpn.networks', ['192.168.50.0/24']);
        $user = Mockery::mock(User::class);
        $user->shouldReceive('hasRole')->with('general_manager')->andReturn($manager);
        if (! $manager) {
            $user->shouldReceive('hasRole')->with('branch_manager')->andReturn(false);
        }

        $request = Request::create('/api/v1/test', $method, server: ['REMOTE_ADDR' => $ip]);
        $request->setUserResolver(fn () => $user);

        return app(RequireVpn::class)->handle($request, fn () => response()->noContent(), $mode);
    }
}
