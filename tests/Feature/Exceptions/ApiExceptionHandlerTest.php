<?php

namespace Tests\Feature\Exceptions;

use App\Exceptions\ApiException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ApiExceptionHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('api/v1/test-validation', function () {
            throw ValidationException::withMessages(['email' => ['El email es requerido']]);
        });

        Route::get('api/v1/test-auth', function () {
            throw new AuthenticationException('Unauthenticated.');
        });

        Route::get('api/v1/test-forbidden', function () {
            throw new AccessDeniedHttpException('This action is unauthorized.');
        });

        Route::get('api/v1/test-not-found', function () {
            throw new NotFoundHttpException('Resource not found.');
        });

        Route::get('api/v1/test-throttle', function () {
            throw new ThrottleRequestsException('Too Many Attempts.');
        });

        Route::get('api/v1/test-throwable', function () {
            throw new \Exception('Un error SQL secreto de base de datos o fallo.');
        });

        Route::get('api/v1/test-api-exception', function () {
            throw new ApiException('CUSTOM_ERROR', 'Mensaje de dominio', 400, ['campo' => 'detalle']);
        });
    }

    public function test_validation_exception_returns_canonical_format()
    {
        $response = $this->getJson('api/v1/test-validation');
        $response->assertStatus(422)
            ->assertJsonStructure(['error' => ['code', 'message', 'fields', 'details', 'request_id']])
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.fields.email.0', 'El email es requerido');
    }

    public function test_authentication_exception_returns_canonical_format()
    {
        $response = $this->getJson('api/v1/test-auth');
        $response->assertStatus(401)
            ->assertJsonStructure(['error' => ['code', 'message', 'fields', 'details', 'request_id']])
            ->assertJsonPath('error.code', 'SESSION_EXPIRED');
    }

    public function test_access_denied_exception_returns_canonical_format()
    {
        $response = $this->getJson('api/v1/test-forbidden');
        $response->assertStatus(403)
            ->assertJsonStructure(['error' => ['code', 'message', 'fields', 'details', 'request_id']])
            ->assertJsonPath('error.code', 'AUTH_SCOPE_DENIED');
    }

    public function test_not_found_http_exception_returns_canonical_format()
    {
        $response = $this->getJson('api/v1/test-not-found');
        $response->assertStatus(404)
            ->assertJsonStructure(['error' => ['code', 'message', 'fields', 'details', 'request_id']])
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    public function test_throttle_requests_exception_returns_canonical_format()
    {
        $response = $this->getJson('api/v1/test-throttle');
        $response->assertStatus(429)
            ->assertJsonStructure(['error' => ['code', 'message', 'fields', 'details', 'request_id']])
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED');
    }

    public function test_throwable_returns_canonical_format_and_hides_message()
    {
        $response = $this->getJson('api/v1/test-throwable');
        $response->assertStatus(500)
            ->assertJsonStructure(['error' => ['code', 'message', 'fields', 'details', 'request_id']])
            ->assertJsonPath('error.code', 'INTERNAL_ERROR');

        $this->assertStringNotContainsString('SQL', $response->getContent());
    }

    public function test_custom_api_exception_returns_canonical_format()
    {
        $response = $this->getJson('api/v1/test-api-exception');
        $response->assertStatus(400)
            ->assertJsonStructure(['error' => ['code', 'message', 'fields', 'details', 'request_id']])
            ->assertJsonPath('error.code', 'CUSTOM_ERROR')
            ->assertJsonPath('error.fields.campo', 'detalle');
    }
}
