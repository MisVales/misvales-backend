<?php

use App\Http\Middleware\CheckBranchScope;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\RequireActiveUser;
use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\TraceRequest;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\SolicitudDistribuidora;
use App\Modules\Organization\Domain\Assignments\Exceptions\AssignmentAlreadyClosed;
use App\Modules\Organization\Domain\Assignments\Exceptions\AssignmentNotFound;
use App\Modules\Organization\Domain\Assignments\Exceptions\DuplicateActiveAssignment;
use App\Modules\Organization\Domain\Assignments\Exceptions\InvalidAssignmentPeriod;
use App\Modules\Organization\Domain\Assignments\Exceptions\InvalidOrganizationAssignment;
use App\Modules\Organization\Domain\Assignments\Exceptions\OrganizationScopeDenied;
use App\Modules\Organization\Domain\Assignments\Exceptions\RoleScopeNotAllowed;
use App\Modules\Organization\Domain\Assignments\Exceptions\UserNotAssignable;
use App\Modules\Organization\Domain\Branches\Exceptions\BranchHasActiveAssignments;
use App\Modules\Organization\Domain\Branches\Exceptions\BranchInactive;
use App\Modules\Organization\Domain\Branches\Exceptions\BranchNotFound;
use App\Modules\Organization\Domain\Branches\Exceptions\BranchVersionConflict;
use App\Modules\Organization\Domain\Branches\Exceptions\DuplicateBranchCode;
use App\Modules\Organization\Domain\Branches\Exceptions\HeadquartersBranchProtected;
use App\Services\Audit\SecurityAuditService;
use App\Services\SolicitudDistribuidora\AuditorSolicitudDistribuidora;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
            'idempotency' => EnforceIdempotency::class,
        ]);

        // Aplicamos el trazador a TODAS las peticiones HTTP
        $middleware->append(TraceRequest::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $organizationError = static function (Throwable $exception) {
            [$code, $status] = match ($exception::class) {
                BranchVersionConflict::class => ['VERSION_CONFLICT', 409],
                HeadquartersBranchProtected::class => ['HEADQUARTERS_BRANCH_PROTECTED', 409],
                BranchHasActiveAssignments::class => ['BRANCH_HAS_ACTIVE_ASSIGNMENTS', 409],
                DuplicateBranchCode::class => ['DUPLICATE_BRANCH_CODE', 409],
                BranchInactive::class => ['BRANCH_INACTIVE', 409],
                BranchNotFound::class => ['BRANCH_NOT_FOUND', 404],
                OrganizationScopeDenied::class => ['ORGANIZATION_SCOPE_DENIED', 403],
                DuplicateActiveAssignment::class => ['DUPLICATE_ACTIVE_ASSIGNMENT', 409],
                AssignmentAlreadyClosed::class => ['ASSIGNMENT_ALREADY_CLOSED', 409],
                AssignmentNotFound::class => ['ASSIGNMENT_NOT_FOUND', 404],
                UserNotAssignable::class => ['USER_NOT_ASSIGNABLE', 422],
                RoleScopeNotAllowed::class => ['ROLE_SCOPE_NOT_ALLOWED', 422],
                InvalidAssignmentPeriod::class => ['INVALID_ASSIGNMENT_PERIOD', 422],
                InvalidOrganizationAssignment::class => ['INVALID_ORGANIZATION_ASSIGNMENT', 422],
            };

            return response()->json([
                'code' => $code,
                'message' => $exception->getMessage(),
            ], $status);
        };

        $exceptions->renderable(function (Throwable $exception, Request $request) use ($organizationError) {
            if (! $exception instanceof BranchVersionConflict
                && ! $exception instanceof HeadquartersBranchProtected
                && ! $exception instanceof BranchHasActiveAssignments
                && ! $exception instanceof DuplicateBranchCode
                && ! $exception instanceof BranchInactive
                && ! $exception instanceof BranchNotFound
                && ! $exception instanceof OrganizationScopeDenied
                && ! $exception instanceof DuplicateActiveAssignment
                && ! $exception instanceof AssignmentAlreadyClosed
                && ! $exception instanceof AssignmentNotFound
                && ! $exception instanceof UserNotAssignable
                && ! $exception instanceof RoleScopeNotAllowed
                && ! $exception instanceof InvalidAssignmentPeriod
                && ! $exception instanceof InvalidOrganizationAssignment) {
                return null;
            }

            return $organizationError($exception);
        });

        $distributorError = function (Request $request, string $code, string $message, int $status, array|object $details = []) {
            return response()->json(['error' => [
                'code' => $code,
                'message' => $message,
                'fields' => $status === 422 ? (object) $details : (object) [],
                'details' => $status !== 422 ? (object) $details : (object) [],
                'request_id' => $request->attributes->get('request_id'),
            ]], $status);
        };

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                $model = $e->getModel();
                if ($request->is('api/v1/clients*')) {
                    return response()->json(['error' => [
                        'code' => 'CLIENT_NOT_FOUND',
                        'message' => 'El cliente o movimiento no existe o no está dentro del alcance autorizado.',
                        'fields' => (object) [],
                        'details' => (object) [],
                        'request_id' => $request->attributes->get('request_id'),
                    ]], 404);
                }
                if ($request->is('api/v1/distributors*')) {
                    return response()->json(['error' => [
                        'code' => 'DISTRIBUTOR_NOT_FOUND',
                        'message' => 'La distribuidora no existe o no está dentro del alcance autorizado.',
                        'fields' => (object) [],
                        'details' => (object) [],
                        'request_id' => $request->attributes->get('request_id'),
                    ]], 404);
                }
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
            if ($request->is('api/v1/distributor-applications*')) {
                $application = $request->route('application');
                $branchId = $application instanceof SolicitudDistribuidora
                    ? $application->branch_id
                    : $request->input('branch_id');

                app(SecurityAuditService::class)->log($request, [
                    'event_type' => 'DISTRIBUTOR_APPLICATION_ACCESS_DENIED',
                    'severity' => 'WARNING',
                    'outcome' => 'DENIED',
                    'branch_id' => $branchId,
                    'entity_type' => 'distributor_application',
                    'entity_id' => $application instanceof SolicitudDistribuidora ? $application->id : null,
                    'metadata' => [
                        'application_id' => $application instanceof SolicitudDistribuidora ? $application->id : null,
                        'branch_id' => $branchId,
                        'reason' => $e->getMessage(),
                        'path' => $request->path(),
                        'result' => 'DENIED',
                    ],
                ]);

                return response()->json(['error' => [
                    'code' => 'AUTH_SCOPE_DENIED',
                    'message' => 'El recurso no está dentro del alcance autorizado.',
                    'fields' => (object) [],
                    'details' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ]], 403);
            }

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

            if ($request->is('api/v1/distributors*')) {
                return response()->json(['error' => [
                    'code' => 'AUTH_SCOPE_DENIED',
                    'message' => 'El recurso no está dentro del alcance autorizado.',
                    'fields' => (object) [],
                    'details' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ]], 403);
            }

            if ($request->is('api/v1/distributor-applications*')) {
                try {
                    $solicitud = $request->route('application');

                    if ($solicitud instanceof SolicitudDistribuidora && $request->user() !== null) {
                        app(AuditorSolicitudDistribuidora::class)->registrar(
                            $request->user(),
                            $solicitud,
                            'DISTRIBUTOR_APPLICATION_ACCESS_DENIED',
                            [],
                            [],
                            $e->getMessage(),
                            'DENIED',
                        );
                    } else {
                        app(SecurityAuditService::class)->log($request, [
                            'event_type' => 'DISTRIBUTOR_APPLICATION_ACCESS_DENIED',
                            'severity' => 'WARNING',
                            'outcome' => 'DENIED',
                            'branch_id' => $request->input('branch_id'),
                            'entity_type' => 'distributor_application',
                            'entity_id' => is_string($solicitud) ? $solicitud : null,
                            'metadata' => [
                                'application_id' => is_string($solicitud) ? $solicitud : null,
                                'application_number' => null,
                                'branch_id' => $request->input('branch_id'),
                                'action' => $request->method(),
                                'previous_values' => [],
                                'new_values' => [],
                                'reason' => $e->getMessage(),
                                'path' => $request->path(),
                                'result' => 'DENIED',
                            ],
                        ]);
                    }
                } catch (Throwable) {
                    // La auditoría nunca debe ocultar la respuesta de autorización.
                }

                return response()->json(['error' => [
                    'code' => 'AUTH_SCOPE_DENIED',
                    'message' => 'El recurso no está dentro del alcance autorizado.',
                    'fields' => (object) [],
                    'details' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ]], 403);
            }

            return response()->json(['error' => 'PERMISSION_DENIED', 'message' => 'Acceso denegado.'], 403);
        });

        $exceptions->renderable(function (ValidationException $e, Request $request) use ($distributorError) {
            if ($request->is('api/v1/branches*')
                || $request->is('api/v1/personnel*')
                || $request->is('api/v1/users/*/assignments*')) {
                return response()->json([
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Los datos enviados no son válidos.',
                    'fields' => (object) $e->errors(),
                    'details' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ], 422);
            }

            if ($request->is('api/v1/distributors*')) {
                return $distributorError(
                    $request,
                    'DISTRIBUTOR_VALIDATION_FAILED',
                    'Los datos enviados no cumplen las reglas de la operación.',
                    422,
                    $e->errors(),
                );
            }

            if ($request->is('api/v1/distributor-applications/*/activation')) {
                return $distributorError(
                    $request,
                    'DISTRIBUTOR_VALIDATION_FAILED',
                    'Los datos enviados no cumplen las reglas de activación.',
                    422,
                    $e->errors(),
                );
            }

            if (! $request->is('api/v1/distributor-applications*')) {
                return null;
            }

            $campos = $e->errors();
            $claves = array_keys($campos);
            $mensajes = mb_strtolower(json_encode($campos, JSON_UNESCAPED_UNICODE) ?: '');
            $code = match (true) {
                isset($campos['sections']) => 'DISTRIBUTOR_APPLICATION_INCOMPLETE',
                isset($campos['branch_id']) => 'DISTRIBUTOR_APPLICATION_BRANCH_INVALID',
                isset($campos['coordinator_id']) => 'DISTRIBUTOR_APPLICATION_COORDINATOR_INVALID',
                isset($campos['is_current']) => 'DISTRIBUTOR_APPLICATION_CURRENT_RESIDENCE_DUPLICATE',
                isset($campos['residence']) => 'DISTRIBUTOR_APPLICATION_CURRENT_RESIDENCE_REQUIRED',
                collect($claves)->contains(fn (string $key): bool => str_starts_with($key, 'section_declarations.')) && str_contains($mensajes, 'no aplicable') => 'DISTRIBUTOR_APPLICATION_SECTION_NOT_APPLICABLE',
                collect($claves)->contains(fn (string $key): bool => str_starts_with($key, 'section_declarations.')) => 'DISTRIBUTOR_APPLICATION_SECTION_INCOMPLETE',
                collect($claves)->contains(fn (string $key): bool => in_array($key, ['curp', 'rfc', 'official_id_number'], true)) => 'DISTRIBUTOR_APPLICATION_SENSITIVE_DATA_INVALID',
                default => 'DISTRIBUTOR_APPLICATION_SECTION_INCOMPLETE',
            };

            return $distributorError($request, $code, 'Los datos enviados no cumplen las reglas de la solicitud.', 422, $campos);
        });

        $exceptions->renderable(function (ConflictHttpException $e, Request $request) use ($distributorError) {
            if (! $request->is('api/v1/distributor-applications*')) {
                return null;
            }

            try {
                $solicitud = $request->route('application');

                if ($solicitud instanceof SolicitudDistribuidora && $request->user() !== null) {
                    app(AuditorSolicitudDistribuidora::class)->registrar(
                        $request->user(),
                        $solicitud,
                        'DISTRIBUTOR_APPLICATION_ACCESS_DENIED',
                        ['status' => $solicitud->status->value],
                        [],
                        $e->getMessage(),
                        'DENIED',
                    );
                }
            } catch (Throwable) {
                // La auditoría nunca debe ocultar la respuesta de conflicto.
            }

            $version = str_contains(mb_strtolower($e->getMessage()), 'versión');
            $yaEnviada = str_contains(mb_strtolower($e->getMessage()), 'ya fue enviada');

            return $distributorError(
                $request,
                $version ? 'RESOURCE_VERSION_CONFLICT' : ($yaEnviada ? 'DISTRIBUTOR_APPLICATION_ALREADY_SUBMITTED' : 'DISTRIBUTOR_APPLICATION_NOT_EDITABLE'),
                $e->getMessage(),
                409,
            );
        });

        $exceptions->renderable(function (ModelNotFoundException $e, Request $request) use ($distributorError) {
            if (! $request->is('api/v1/distributor-applications*')) {
                return null;
            }

            $esSolicitud = $request->route('application') === null || is_string($request->route('application'));

            return $distributorError(
                $request,
                $esSolicitud ? 'DISTRIBUTOR_APPLICATION_NOT_FOUND' : 'DISTRIBUTOR_APPLICATION_CHILD_NOT_FOUND',
                'El recurso solicitado no existe.',
                404,
            );
        });

        $exceptions->renderable(function (NotFoundHttpException $e, Request $request) use ($distributorError) {
            if ($request->is('api/v1/clients*')) {
                return $distributorError(
                    $request,
                    'CLIENT_NOT_FOUND',
                    'El cliente o movimiento no existe o no está dentro del alcance autorizado.',
                    404,
                );
            }

            if (! $request->is('api/v1/distributor-applications*') || $request->route() === null || $request->route('application') === null) {
                return null;
            }

            return $distributorError(
                $request,
                'DISTRIBUTOR_APPLICATION_NOT_FOUND',
                'La solicitud no existe o no está dentro del alcance autorizado.',
                404,
            );
        });

        $exceptions->renderable(function (AuthenticationException $e, Request $request) {
            return response()->json(['error' => 'INVALID_SESSION', 'message' => 'No autenticado o sesión inválida.'], 401);
        });

        $exceptions->renderable(function (ThrottleRequestsException $e, Request $request) {
            return response()->json(['error' => 'RATE_LIMIT_EXCEEDED', 'message' => 'Demasiadas solicitudes. Intente nuevamente más tarde.'], 429);
        });

        $exceptions->renderable(function (Exception $e, Request $request) {
            if ($e->getCode() === 426) {
                return response()->json(['error' => 'VERSION_CONFLICT', 'message' => 'La versión de la aplicación cliente no es compatible.'], 426);
            }
        });
    })->create();
