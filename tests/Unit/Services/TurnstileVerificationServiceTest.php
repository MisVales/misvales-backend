<?php

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Services\Security\TurnstileVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TurnstileVerificationServiceTest extends TestCase
{
    private TurnstileVerificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.turnstile.enabled', true);
        $this->service = new TurnstileVerificationService;
    }

    public function test_returns_true_when_turnstile_is_disabled(): void
    {
        Config::set('services.turnstile.enabled', false);
        Config::set('services.turnstile.secret', null);
        $request = Request::create('/api/v1/auth/login', 'POST');

        $result = $this->service->verify($request, null);

        $this->assertTrue($result);
    }

    public function test_case_b_returns_true_when_secret_and_token_are_present_and_cloudflare_validates_successfully(): void
    {
        Config::set('services.turnstile.secret', 'valid-secret-key');

        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => true,
            ], 200),
        ]);

        $request = Request::create('/api/v1/auth/login', 'POST');
        $result = $this->service->verify($request, 'cf-token-123');

        $this->assertTrue($result);

        Http::assertSent(function ($req) {
            return $req->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
                && $req['secret'] === 'valid-secret-key'
                && $req['response'] === 'cf-token-123';
        });
    }

    public function test_case_b_throws_422_when_cloudflare_verification_fails(): void
    {
        Config::set('services.turnstile.secret', 'valid-secret-key');

        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => false,
                'error-codes' => ['invalid-input-response'],
            ], 200),
        ]);

        $request = Request::create('/api/v1/auth/login', 'POST');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('La verificación de seguridad es inválida o ha expirado.');

        try {
            $this->service->verify($request, 'invalid-token');
        } catch (ApiException $e) {
            $this->assertSame('INVALID_TURNSTILE', $e->errorCode);
            $this->assertSame(422, $e->httpStatus);
            throw $e;
        }
    }

    public function test_case_c_inconsistency_throws_500_when_token_provided_but_no_secret_configured(): void
    {
        Config::set('services.turnstile.secret', '');
        $request = Request::create('/api/v1/auth/login', 'POST');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Error de configuración en el servicio de seguridad.');

        try {
            $this->service->verify($request, 'token-sent-by-frontend');
        } catch (ApiException $e) {
            $this->assertSame('CONFIG_ERROR', $e->errorCode);
            $this->assertSame(500, $e->httpStatus);
            throw $e;
        }
    }

    public function test_case_d_inconsistency_throws_422_when_secret_configured_but_token_is_missing(): void
    {
        Config::set('services.turnstile.secret', 'valid-secret-key');
        $request = Request::create('/api/v1/auth/login', 'POST');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('La verificación de seguridad es obligatoria.');

        try {
            $this->service->verify($request, null);
        } catch (ApiException $e) {
            $this->assertSame('TURNSTILE_REQUIRED', $e->errorCode);
            $this->assertSame(422, $e->httpStatus);
            throw $e;
        }
    }
}
