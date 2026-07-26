<?php

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccessRuleViolation;
use App\Modules\Access\Presentation\Http\Middleware\CaptureSecurityContextMiddleware;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;
use App\Modules\Client\Presentation\Http\Middleware\EnsureClientRequestId;
use App\Modules\Credit\Application\Services\CreditRecorder;
use App\Modules\Credit\Domain\Exceptions\CreditRuleViolation;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditIncreaseRequestModel;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;
use App\Modules\DistributorOnboarding\Presentation\Http\Middleware\AuditOnboardingFailure;
use App\Modules\DistributorOnboarding\Presentation\Http\Middleware\EnsureRequestId;
use App\Modules\ExcessBalance\Application\DTOs\OperationContext;
use App\Modules\ExcessBalance\Application\Services\ExcessRecorder;
use App\Modules\ExcessBalance\Domain\Exceptions\ExcessBalanceException;
use App\Modules\Mobility\Domain\Exceptions\MobilityException;
use App\Modules\Payment\Domain\Exceptions\PaymentDomainException;
use App\Modules\Points\Domain\Exceptions\PointsDomainException;
use App\Modules\Reporting\Domain\Exceptions\ReportingException;
use App\Modules\RiskDelinquency\Domain\Exceptions\RiskDelinquencyException;
use App\Modules\Voucher\Application\DTOs\OperationMetadata;
use App\Modules\Voucher\Application\Security\VoucherActorContextFactory;
use App\Modules\Voucher\Application\Services\VoucherRecorder;
use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;
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
            'client.request-id' => EnsureClientRequestId::class,
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
        $exceptions->render(function (ClientDomainException $exception, Request $request) {
            return response()->json([
                'error' => [
                    'code' => $exception->errorCode(),
                    'message' => $exception->getMessage(),
                    'fields' => $exception->fields() === [] ? (object) [] : $exception->fields(),
                    'details' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], $exception->httpStatus(), [
                'X-Request-Id' => (string) $request->attributes->get('request_id'),
            ]);
        });
        $exceptions->render(function (VoucherDomainException $exception, Request $request) {
            if (in_array($exception->errorCode(), [
                'MODIFICATION_TOKEN_INVALID',
                'MODIFICATION_TOKEN_EXPIRED',
                'MODIFICATION_TOKEN_USED',
            ], true)) {
                try {
                    $user = $request->user();
                    $actor = $user instanceof User
                        ? app(VoucherActorContextFactory::class)->fromUser($user)
                        : null;
                    app(VoucherRecorder::class)->audit(
                        'VOUCHER_MODIFICATION_TOKEN_ATTEMPT',
                        'DENIED',
                        null,
                        $actor,
                        new OperationMetadata(
                            requestId: (string) ($request->header('X-Request-Id') ?? Str::uuid()),
                            idempotencyKey: (string) ($request->header('Idempotency-Key') ?? ''),
                            ip: $request->ip(),
                            userAgent: $request->userAgent(),
                        ),
                        ['modification_request_id' => $request->route('modificationRequest')],
                        $exception->errorCode(),
                    );
                } catch (Throwable) {
                    // La auditoría nunca altera ni filtra la respuesta estable del dominio.
                }
            }

            return response()->json([
                'error' => [
                    'code' => $exception->errorCode(),
                    'message' => $exception->getMessage(),
                    'fields' => $exception->fields() === [] ? (object) [] : $exception->fields(),
                    'details' => (object) [],
                    'request_id' => $request->attributes->get(
                        'request_id',
                        $request->header('X-Request-Id'),
                    ),
                ],
            ], $exception->httpStatus(), [
                'X-Request-Id' => (string) ($request->attributes->get(
                    'request_id',
                    $request->header('X-Request-Id'),
                ) ?? ''),
            ]);
        });
        $exceptions->render(function (PaymentDomainException $exception, Request $request) {
            return response()->json([
                'error' => [
                    'code' => $exception->errorCode(),
                    'message' => $exception->getMessage(),
                    'fields' => $exception->fields() === [] ? (object) [] : $exception->fields(),
                    'details' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], $exception->httpStatus(), [
                'X-Request-Id' => (string) $request->attributes->get('request_id'),
            ]);
        });
        $exceptions->render(function (ExcessBalanceException $exception, Request $request) {
            try {
                $actor = $request->user();
                $context = $actor instanceof User
                    ? new OperationContext(
                        actor: $actor,
                        idempotencyKey: '',
                        correlationId: (string) ($request->attributes->get('request_id') ?? Str::uuid()),
                        ipAddress: $request->ip(),
                        userAgent: $request->userAgent(),
                    )
                    : null;
                $resourceId = collect([
                    $request->route('excessBalance'),
                    $request->route('refundRequest'),
                ])->first(fn (mixed $value): bool => is_string($value) && $value !== '');

                app(ExcessRecorder::class)->audit(
                    'EXCESS_REQUEST_REJECTED',
                    $exception->httpStatus() >= 500 ? 'ERROR' : 'DENIED',
                    'http_request',
                    is_string($resourceId) ? $resourceId : 'unresolved',
                    $context,
                    metadata: [
                        'route' => $request->route()?->getName(),
                        'error_code' => $exception->errorCode(),
                    ],
                    reason: $exception->errorCode(),
                );
            } catch (Throwable) {
                // El registro secundario nunca altera la respuesta estable del dominio.
            }

            return response()->json([
                'error' => [
                    'code' => $exception->errorCode(),
                    'message' => $exception->getMessage(),
                    'fields' => $exception->fields() === [] ? (object) [] : $exception->fields(),
                    'details' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], $exception->httpStatus(), [
                'X-Request-Id' => (string) $request->attributes->get('request_id'),
            ]);
        });
        $exceptions->render(function (PointsDomainException $exception, Request $request) {
            return response()->json([
                'error' => [
                    'code' => $exception->errorCode(),
                    'message' => $exception->getMessage(),
                    'fields' => $exception->fields() === [] ? (object) [] : $exception->fields(),
                    'details' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], $exception->httpStatus(), [
                'X-Request-Id' => (string) $request->attributes->get('request_id'),
            ]);
        });
        $exceptions->render(function (RiskDelinquencyException $exception, Request $request) {
            return response()->json([
                'error' => [
                    'code' => $exception->errorCode(),
                    'message' => $exception->getMessage(),
                    'fields' => $exception->fields() === [] ? (object) [] : $exception->fields(),
                    'details' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], $exception->httpStatus(), [
                'X-Request-Id' => (string) $request->attributes->get('request_id'),
            ]);
        });
        $exceptions->render(function (MobilityException $exception, Request $request) {
            return response()->json([
                'error' => [
                    'code' => $exception->errorCode(),
                    'message' => $exception->getMessage(),
                    'fields' => $exception->fields() === [] ? (object) [] : $exception->fields(),
                    'details' => (object) [],
                    'request_id' => $request->attributes->get('request_id', $request->header('X-Request-Id')),
                ],
            ], $exception->httpStatus(), [
                'X-Request-Id' => (string) ($request->attributes->get('request_id', $request->header('X-Request-Id')) ?? ''),
            ]);
        });
        $exceptions->render(function (ReportingException $exception, Request $request) {
            return response()->json([
                'error' => [
                    'code' => $exception->errorCode(),
                    'message' => $exception->getMessage(),
                    'fields' => $exception->fields() === [] ? (object) [] : $exception->fields(),
                    'details' => (object) [],
                    'request_id' => $request->attributes->get('request_id', $request->header('X-Request-Id')),
                ],
            ], $exception->httpStatus(), [
                'X-Request-Id' => (string) ($request->attributes->get('request_id', $request->header('X-Request-Id')) ?? ''),
            ]);
        });
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->routeIs(
                'api.v1.distributor-applications.*',
                'api.v1.clients.*',
                'api.v1.vouchers.*',
                'api.v1.modification-requests.*',
                'api.v1.bank-imports.*',
                'api.v1.bank-movements.*',
                'api.v1.relations.payments',
                'api.v1.payment-allocations.*',
                'api.v1.clarifications.*',
                'api.v1.manual-reconciliations.*',
                'api.v1.excess-balances.*',
                'api.v1.refunds.*',
                'api.v1.me.excess-balances.*',
                'api.v1.me.refund-requests.*',
                'api.v1.refund-requests.*',
                'api.v1.risk.*',
                'api.v1.delinquency.*',
                'api.v1.client-transfers.*',
                'api.v1.client-reassignments.*',
                'api.v1.distributor-branch-changes.*',
                'api.v1.coordinator-reassignments.*',
                'api.v1.reports.*',
                'api.v1.report-runs.*',
            )) {
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
            if (! $request->routeIs(
                'api.v1.distributor-applications.*',
                'api.v1.clients.*',
                'api.v1.vouchers.*',
                'api.v1.modification-requests.*',
                'api.v1.bank-imports.*',
                'api.v1.bank-movements.*',
                'api.v1.relations.payments',
                'api.v1.payment-allocations.*',
                'api.v1.clarifications.*',
                'api.v1.manual-reconciliations.*',
                'api.v1.excess-balances.*',
                'api.v1.refunds.*',
                'api.v1.me.excess-balances.*',
                'api.v1.me.refund-requests.*',
                'api.v1.refund-requests.*',
                'api.v1.risk.*',
                'api.v1.delinquency.*',
                'api.v1.client-transfers.*',
                'api.v1.client-reassignments.*',
                'api.v1.distributor-branch-changes.*',
                'api.v1.coordinator-reassignments.*',
                'api.v1.reports.*',
                'api.v1.report-runs.*',
            )) {
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
            if (! $request->routeIs(
                'api.v1.distributor-applications.*',
                'api.v1.clients.*',
                'api.v1.vouchers.*',
                'api.v1.modification-requests.*',
                'api.v1.bank-imports.*',
                'api.v1.bank-movements.*',
                'api.v1.relations.payments',
                'api.v1.payment-allocations.*',
                'api.v1.clarifications.*',
                'api.v1.manual-reconciliations.*',
                'api.v1.excess-balances.*',
                'api.v1.refunds.*',
                'api.v1.me.excess-balances.*',
                'api.v1.me.refund-requests.*',
                'api.v1.refund-requests.*',
                'api.v1.risk.*',
                'api.v1.delinquency.*',
                'api.v1.client-transfers.*',
                'api.v1.client-reassignments.*',
                'api.v1.distributor-branch-changes.*',
                'api.v1.coordinator-reassignments.*',
                'api.v1.reports.*',
                'api.v1.report-runs.*',
            )) {
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
