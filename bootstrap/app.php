<?php

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccessRuleViolation;
use App\Modules\Access\Presentation\Http\Middleware\CaptureSecurityContextMiddleware;
use App\Modules\Credit\Application\Services\CreditRecorder;
use App\Modules\Credit\Domain\Exceptions\CreditRuleViolation;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditIncreaseRequestModel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
    })->create();
