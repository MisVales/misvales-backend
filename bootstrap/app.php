<?php

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccessRuleViolation;
use App\Modules\Access\Presentation\Http\Middleware\CaptureSecurityContextMiddleware;
use App\Modules\Credit\Application\Services\CreditRecorder;
use App\Modules\Credit\Domain\Exceptions\CreditRuleViolation;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditIncreaseRequestModel;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;
use App\Modules\DistributorOnboarding\Presentation\Http\Middleware\AuditOnboardingFailure;
use App\Modules\DistributorOnboarding\Presentation\Http\Middleware\EnsureRequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->append(CaptureSecurityContextMiddleware::class);
        $middleware->alias([
            'onboarding.failures' => AuditOnboardingFailure::class,
            'request.id' => EnsureRequestId::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        $exceptions->render(function (AccessRuleViolation $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => $exception->errorCode(),
                    'message' => $exception->getMessage(),
                    'correlationId' => $request->attributes->get('correlation_id', (string) Str::uuid()),
                ],
            ], $exception->statusCode());
        });
        $exceptions->render(function (CreditRuleViolation $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            if (in_array($exception->errorCode(), [
                'AUTH_SCOPE_DENIED',
                'RESOURCE_VERSION_CONFLICT',
                'CREDIT_MOVEMENT_DUPLICATE',
            ], true)) {
                try {
                    $actor = $request->user();
                    $distributor = $request->route('distributor');
                    $increaseRequest = $request->route('creditIncreaseRequest');
                    $distributorId = $distributor instanceof User
                        ? $distributor->id
                        : ($increaseRequest instanceof CreditIncreaseRequestModel ? $increaseRequest->distributor_id : null);
                    $branchId = $distributor instanceof User
                        ? $distributor->branch_id
                        : ($increaseRequest instanceof CreditIncreaseRequestModel ? $increaseRequest->branch_id : null);
                    app(CreditRecorder::class)->audit(
                        'CREDIT_OPERATION_DENIED',
                        'DENIED',
                        $actor instanceof User ? $actor : null,
                        $distributorId,
                        $branchId,
                        $increaseRequest instanceof CreditIncreaseRequestModel ? 'credit_increase_requests' : 'credit_lines',
                        $increaseRequest instanceof CreditIncreaseRequestModel ? $increaseRequest->public_id : null,
                        reason: $exception->getMessage(),
                        metadata: ['error_code' => $exception->errorCode()],
                    );
                } catch (Throwable) {
                    // Audit failures must not leak internals or alter the stable domain response.
                }
            }

            return response()->json([
                'error' => [
                    'code' => $exception->errorCode(),
                    'message' => $exception->getMessage(),
                    'correlationId' => $request->attributes->get('correlation_id', (string) Str::uuid()),
                ],
            ], $exception->statusCode());
        });
        $exceptions->render(function (OnboardingDomainException $exception, Request $request) {
            return response()->json([
                'error' => [
                    'code' => $exception->errorCode(),
                    'message' => $exception->getMessage(),
                    'fields' => $exception->fields(),
                    'details' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], $exception->httpStatus(), [
                'X-Request-Id' => (string) $request->attributes->get('request_id'),
            ]);
        });
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->routeIs('api.v1.distributor-applications.*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'La petición contiene campos inválidos.',
                    'fields' => $exception->errors(),
                    'details' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], 422, [
                'X-Request-Id' => (string) $request->attributes->get('request_id'),
            ]);
        });
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->routeIs('api.v1.distributor-applications.*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'AUTHENTICATION_REQUIRED',
                    'message' => 'La operación requiere una sesión activa.',
                    'fields' => (object) [],
                    'details' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], 401, [
                'X-Request-Id' => (string) $request->attributes->get('request_id'),
            ]);
        });
        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            if (! $request->routeIs('api.v1.distributor-applications.*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'AUTH_SCOPE_DENIED',
                    'message' => 'La cuenta no tiene autoridad para ejecutar la acción.',
                    'fields' => (object) [],
                    'details' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], 403, [
                'X-Request-Id' => (string) $request->attributes->get('request_id'),
            ]);
        });
    })->create();
