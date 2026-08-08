<?php

use App\Http\Middleware\CheckBranchScope;
use App\Http\Middleware\RequireActiveUser;
use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\TraceRequest;
use App\Http\Middleware\TrackSessionActivity;
use App\Services\Audit\SecurityAuditService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        // Tracker de sesiones y Zero Trust Suite
        $middleware->alias([
            'track.activity' => TrackSessionActivity::class,
            'active.user' => RequireActiveUser::class,
            'mfa.completed' => RequireMfaCompleted::class,
            'permission' => RequirePermission::class,
            'branch.scope' => CheckBranchScope::class,
        ]);

        // Aplicamos el trazador a TODAS las peticiones HTTP
        $middleware->append(TraceRequest::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                $model = $e->getModel();
                if (str_contains($model, 'Category')) {
                    return response()->json(['error' => 'CATEGORY_NOT_FOUND', 'message' => 'Categoría inexistente.'], 404);
                }
                if (str_contains($model, 'Product')) {
                    return response()->json(['error' => 'PRODUCT_NOT_FOUND', 'message' => 'Producto inexistente.'], 404);
                }
                return response()->json(['error' => 'RESOURCE_NOT_FOUND', 'message' => 'Recurso inexistente.'], 404);
            }
        });

        $exceptions->dontFlash([
            'password',
            'password_confirmation',
            'token',
            'exchange_token',
            'totp_code',
            'recovery_code',
        ]);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->renderable(function (AccessDeniedHttpException $e, Request $request) {
            app(SecurityAuditService::class)->log($request, [
                'event_type' => 'AUTHZ_REJECTED',
                'severity' => 'WARNING',
                'outcome' => 'DENIED',
                'metadata' => ['message' => $e->getMessage(), 'path' => $request->path()],
            ]);
        });

        $exceptions->renderable(function (AuthorizationException $e, Request $request) {
            app(SecurityAuditService::class)->log($request, [
                'event_type' => 'AUTHZ_REJECTED',
                'severity' => 'WARNING',
                'outcome' => 'DENIED',
                'metadata' => ['message' => $e->getMessage(), 'path' => $request->path()],
            ]);

            return response()->json(['error' => 'PERMISSION_DENIED', 'message' => 'Acceso denegado.'], 403);
        });

        $exceptions->renderable(function (AuthenticationException $e, Request $request) {
            return response()->json(['error' => 'INVALID_SESSION', 'message' => 'No autenticado o sesión inválida.'], 401);
        });

        $exceptions->renderable(function (ThrottleRequestsException $e, Request $request) {
            return response()->json(['error' => 'RATE_LIMIT_EXCEEDED', 'message' => 'Demasiadas solicitudes. Intente nuevamente más tarde.'], 429);
        });

        $exceptions->renderable(function (Exception $e, Request $request) {
            if ($e->getCode() === 426 || str_contains($e->getMessage(), 'version')) {
                return response()->json(['error' => 'VERSION_CONFLICT', 'message' => 'La versión de la aplicación cliente no es compatible.'], 426);
            }
        });
    })->create();
