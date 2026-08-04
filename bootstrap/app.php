<?php

use App\Http\Middleware\CheckBranchScope;
use App\Http\Middleware\RequireActiveUser;
use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\TraceRequest;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\SolicitudDistribuidora;
use App\Models\UserRoleScope;
use App\Modules\Organization\Application\Events\OrganizationEventPublisher;
use App\Modules\Organization\Domain\Assignments\Exceptions\AssignmentAlreadyClosed;
use App\Modules\Organization\Domain\Assignments\Exceptions\AssignmentNotFound;
use App\Modules\Organization\Domain\Assignments\Exceptions\DuplicateActiveAssignment;
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
use App\Modules\Organization\Domain\Events\OrganizationEvent;
use App\Modules\Organization\Domain\Events\OrganizationEventType;
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
use Illuminate\Support\Str;
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
        ]);

        // Aplicamos el trazador a TODAS las peticiones HTTP
        $middleware->append(TraceRequest::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $distributorError = fn (Request $request, string $code, string $message, int $status, array $fields = [], array $details = []) => response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'fields' => $fields === [] ? (object) [] : $fields,
                'details' => $details === [] ? (object) [] : $details,
                'request_id' => $request->attributes->get('request_id'),
            ],
        ], $status);

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
                            'entity_type' => 'distributor_application',
                            'entity_id' => is_string($solicitud) ? $solicitud : null,
                            'metadata' => [
                                'application_id' => is_string($solicitud) ? $solicitud : null,
                                'application_number' => null,
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

        $organizationError = fn (Request $request, string $code, string $message, int $status) => response()->json([
            'code' => $code,
            'message' => $message,
            'fields' => (object) [],
            'details' => (object) [],
            'request_id' => $request->attributes->get('request_id'),
        ], $status);

        $exceptions->renderable(function (BranchNotFound $e, Request $request) use ($organizationError) {
            return $organizationError($request, 'BRANCH_NOT_FOUND', $e->getMessage(), 404);
        });

        $exceptions->renderable(function (DuplicateBranchCode $e, Request $request) use ($organizationError) {
            return $organizationError($request, 'DUPLICATE_BRANCH_CODE', $e->getMessage(), 409);
        });

        $exceptions->renderable(function (BranchVersionConflict $e, Request $request) use ($organizationError) {
            return $organizationError($request, 'VERSION_CONFLICT', $e->getMessage(), 409);
        });

        $exceptions->renderable(function (OrganizationScopeDenied $e, Request $request) use ($organizationError) {
            try {
                $generalManagerIds = UserRoleScope::query()
                    ->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
                    ->where('roles.code', 'general_manager')
                    ->where('user_role_scopes.scope_type', 'GLOBAL')
                    ->where('user_role_scopes.status', 'ACTIVE')
                    ->whereNull('user_role_scopes.revoked_at')
                    ->pluck('user_role_scopes.user_id')
                    ->unique()
                    ->values()
                    ->all();
                $routeId = (string) ($request->route('assignmentId') ?? $request->route('id') ?? $request->path());
                $isBranchRoute = str_contains($request->path(), 'branches/');
                $isUserRoute = str_contains($request->path(), 'users/');

                app(OrganizationEventPublisher::class)->publish(new OrganizationEvent(
                    id: Str::uuid()->toString(),
                    type: OrganizationEventType::ORGANIZATION_SCOPE_DENIED,
                    aggregateType: 'organization_request',
                    aggregateId: $routeId,
                    actorId: $request->user()?->id,
                    affectedUserId: $isUserRoute ? (string) $request->route('id') : null,
                    branchId: $isBranchRoute ? (string) $request->route('id') : null,
                    reason: $e->getMessage(),
                    details: ['method' => $request->method(), 'path' => $request->path()],
                    notifyUserIds: $generalManagerIds,
                    outcome: 'DENIED',
                ));
            } catch (Throwable) {
                // La respuesta de autorización nunca debe quedar oculta por una falla de telemetría.
            }

            return $organizationError($request, 'ORGANIZATION_SCOPE_DENIED', $e->getMessage(), 403);
        });

        $exceptions->renderable(function (HeadquartersBranchProtected $e, Request $request) use ($organizationError) {
            return $organizationError($request, 'HEADQUARTERS_BRANCH_PROTECTED', $e->getMessage(), 409);
        });

        $exceptions->renderable(function (BranchHasActiveAssignments $e, Request $request) use ($organizationError) {
            return $organizationError($request, 'BRANCH_HAS_ACTIVE_ASSIGNMENTS', $e->getMessage(), 409);
        });

        $exceptions->renderable(function (BranchInactive $e, Request $request) use ($organizationError) {
            return $organizationError($request, 'BRANCH_INACTIVE', $e->getMessage(), 409);
        });

        $exceptions->renderable(function (DuplicateActiveAssignment $e, Request $request) use ($organizationError) {
            return $organizationError($request, 'DUPLICATE_ACTIVE_ASSIGNMENT', $e->getMessage(), 409);
        });

        $exceptions->renderable(function (AssignmentNotFound $e, Request $request) use ($organizationError) {
            return $organizationError($request, 'ASSIGNMENT_NOT_FOUND', $e->getMessage(), 404);
        });

        $exceptions->renderable(function (AssignmentAlreadyClosed $e, Request $request) use ($organizationError) {
            return $organizationError($request, 'ASSIGNMENT_ALREADY_CLOSED', $e->getMessage(), 409);
        });

        $exceptions->renderable(function (UserNotAssignable $e, Request $request) use ($organizationError) {
            return $organizationError($request, 'USER_NOT_ASSIGNABLE', $e->getMessage(), 422);
        });

        $exceptions->renderable(function (RoleScopeNotAllowed $e, Request $request) use ($organizationError) {
            return $organizationError($request, 'ROLE_SCOPE_NOT_ALLOWED', $e->getMessage(), 422);
        });

        $exceptions->renderable(function (InvalidOrganizationAssignment $e, Request $request) use ($organizationError) {
            return $organizationError($request, 'INVALID_ORGANIZATION_SCOPE', $e->getMessage(), 422);
        });

        $exceptions->renderable(function (Exception $e, Request $request) {
            if ($e->getCode() === 426 || str_contains($e->getMessage(), 'version')) {
                return response()->json(['error' => 'VERSION_CONFLICT', 'message' => 'La versión de la aplicación cliente no es compatible.'], 426);
            }
        });
    })->create();
