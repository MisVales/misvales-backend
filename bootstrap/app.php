<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\CheckBranchScope;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\RequireActiveUser;
use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\TraceRequest;
use App\Http\Middleware\TrackSessionActivity;
use App\Http\Middleware\TrustConfiguredProxies;
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
use App\Modules\Organization\Domain\Branches\Exceptions\AddressValidationUnavailable;
use App\Modules\Organization\Domain\Branches\Exceptions\BranchHasActiveAssignments;
use App\Modules\Organization\Domain\Branches\Exceptions\BranchInactive;
use App\Modules\Organization\Domain\Branches\Exceptions\BranchNotFound;
use App\Modules\Organization\Domain\Branches\Exceptions\BranchVersionConflict;
use App\Modules\Organization\Domain\Branches\Exceptions\DuplicateBranchCode;
use App\Modules\Organization\Domain\Branches\Exceptions\HeadquartersBranchProtected;
use App\Modules\Organization\Domain\Branches\Exceptions\InvalidBranchAddress;
use App\Modules\Organization\Domain\Events\OrganizationEvent;
use App\Modules\Organization\Domain\Events\OrganizationEventType;
use App\Services\Audit\SecurityAuditService;
use App\Services\Credito\AuditorIncrementos;
use App\Services\SolicitudDistribuidora\AuditorSolicitudDistribuidora;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        [
            'prefix' => 'api',
            'middleware' => [
                'api',
                'auth:sanctum',
                'active.user',
                'mfa.completed',
                'throttle:broadcasting',
            ],
        ],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(TrustConfiguredProxies::class);
        $middleware->statefulApi();
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') ? null : route('login'),
        );
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
        $respondError = function (Request $request, string $code, string $message, int $status, array|object $fields = [], array|object $details = []) {
            if ($request->is('api/*')) {
                return response()->json(['error' => [
                    'code' => $code,
                    'message' => $message,
                    'fields' => empty($fields) ? (object) [] : $fields,
                    'details' => empty($details) ? (object) [] : $details,
                    'request_id' => $request->attributes->get('request_id') ?? request()->header('X-Request-Id'),
                ]], $status);
            }

            return null;
        };

        $exceptions->dontFlash([
            'password',
            'password_confirmation',
            'token',
            'exchange_token',
            'totp_code',
            'recovery_code',
        ]);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
        );

        $exceptions->renderable(function (ModelNotFoundException $e, Request $request) use ($respondError) {
            $model = $e->getModel();
            if ($request->is('api/v1/credit-lines*') || str_contains($model, 'LineaCredito')) {
                return $respondError($request, 'CREDIT_LINE_NOT_FOUND', 'La línea de crédito solicitada no existe o no se encuentra dentro del alcance permitido.', 404);
            }
            if ($request->is('api/v1/credit-increase-requests*') || str_contains($model, 'SolicitudIncrementoLinea')) {
                return $respondError($request, 'CREDIT_INCREASE_REQUEST_NOT_FOUND', 'La solicitud de incremento no existe o no se encuentra dentro del alcance permitido.', 404);
            }
            if ($request->is('api/v1/clients*') || str_contains($model, 'Cliente')) {
                return $respondError($request, 'CLIENT_NOT_FOUND', 'El cliente o movimiento no existe o no está dentro del alcance autorizado.', 404);
            }
            if ($request->is('api/v1/distributors*') || str_contains($model, 'Distribuidora')) {
                return $respondError($request, 'DISTRIBUTOR_NOT_FOUND', 'La distribuidora no existe o no está dentro del alcance autorizado.', 404);
            }
            if ($request->is('api/v1/distributor-applications*')) {
                $esSolicitud = $request->route('application') === null || is_string($request->route('application'));

                return $respondError($request, $esSolicitud ? 'DISTRIBUTOR_APPLICATION_NOT_FOUND' : 'DISTRIBUTOR_APPLICATION_CHILD_NOT_FOUND', 'El recurso solicitado no existe.', 404);
            }
            if (str_contains($model, 'Category')) {
                return $respondError($request, 'CATEGORY_NOT_FOUND', 'Categoría inexistente.', 404);
            }
            if (str_contains($model, 'Product')) {
                return $respondError($request, 'PRODUCT_NOT_FOUND', 'Producto inexistente.', 404);
            }

            return $respondError($request, 'RESOURCE_NOT_FOUND', 'No se encontró el registro solicitado.', 404);
        });

        $exceptions->renderable(function (NotFoundHttpException $e, Request $request) use ($respondError) {
            if ($request->is('api/v1/clients*')) {
                return $respondError($request, 'CLIENT_NOT_FOUND', 'El cliente o movimiento no existe o no está dentro del alcance autorizado.', 404);
            }
            if ($request->is('api/v1/distributor-applications*') && $request->route() !== null && $request->route('application') !== null) {
                return $respondError($request, 'DISTRIBUTOR_APPLICATION_NOT_FOUND', 'La solicitud no existe o no está dentro del alcance autorizado.', 404);
            }

            return $respondError($request, 'RESOURCE_NOT_FOUND', 'No se encontró el registro solicitado.', 404);
        });

        $exceptions->renderable(function (AccessDeniedHttpException $e, Request $request) use ($respondError) {
            app(SecurityAuditService::class)->log($request, [
                'event_type' => 'AUTHZ_REJECTED',
                'severity' => 'WARNING',
                'outcome' => 'DENIED',
                'metadata' => ['message' => $e->getMessage(), 'path' => $request->path()],
            ]);
            if ($request->is('api/v1/distributor-applications*')) {
                app(SecurityAuditService::class)->log($request, [
                    'event_type' => 'DISTRIBUTOR_APPLICATION_ACCESS_DENIED',
                    'severity' => 'WARNING',
                    'outcome' => 'DENIED',
                    'entity_type' => 'distributor_application',
                    'entity_id' => is_string($request->route('application')) ? $request->route('application') : null,
                    'branch_id' => $request->input('branch_id'),
                    'metadata' => ['message' => $e->getMessage(), 'path' => $request->path()],
                ]);
            }

            return $respondError($request, 'AUTH_SCOPE_DENIED', 'No tienes permiso para acceder a este registro.', 403);
        });

        $exceptions->renderable(function (AuthorizationException $e, Request $request) use ($respondError) {
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
                        app(AuditorSolicitudDistribuidora::class)->registrar($request->user(), $solicitud, 'DISTRIBUTOR_APPLICATION_ACCESS_DENIED', [], [], $e->getMessage(), 'DENIED');
                    } else {
                        app(SecurityAuditService::class)->log($request, [
                            'event_type' => 'DISTRIBUTOR_APPLICATION_ACCESS_DENIED',
                            'severity' => 'WARNING',
                            'outcome' => 'DENIED',
                            'entity_type' => 'distributor_application',
                            'entity_id' => is_string($solicitud) ? $solicitud : null,
                            'branch_id' => $request->input('branch_id'),
                            'metadata' => ['reason' => $e->getMessage(), 'path' => $request->path(), 'result' => 'DENIED'],
                        ]);
                    }
                } catch (Throwable $th) {
                }

                return $respondError($request, 'AUTH_SCOPE_DENIED', 'No tienes permiso para acceder a este registro.', 403);
            }

            if ($request->is('api/v1/credit-increase-requests*') || $request->is('api/v1/credit-lines*')) {
                try {
                    if ($request->user()) {
                        $routeId = $request->route('solicitudId') ?? $request->route('id') ?? $request->route('linea');
                        app(AuditorIncrementos::class)->registrar('EV-SCOPE-VIOLATION', str_contains($request->path(), 'credit-lines') ? 'credit_lines' : 'credit_increase_requests', is_string($routeId) ? $routeId : null, null, $request->user(), null, [], [], $e->getMessage(), 'DENIED', 'v1.0.0');
                    }
                } catch (Throwable $th) {
                }
                $code = str_contains($request->path(), 'credit-lines') ? 'CREDIT_LINE_SCOPE_DENIED' : 'CREDIT_INCREASE_SCOPE_DENIED';

                return $respondError($request, $code, 'No tienes permiso para acceder a este registro.', 403);
            }

            return $respondError($request, 'AUTH_SCOPE_DENIED', 'No tienes permiso para acceder a este registro.', 403);
        });

        $exceptions->renderable(function (ValidationException $e, Request $request) use ($respondError) {
            $campos = $e->errors();

            if ($request->is('api/v1/distributor-applications*')) {
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

                return $respondError($request, $code, 'Los datos enviados no cumplen las reglas de la solicitud.', 422, $campos);
            }

            return $respondError($request, 'VALIDATION_ERROR', 'Revisa los campos marcados e inténtalo nuevamente.', 422, $campos);
        });

        $exceptions->renderable(function (ConflictHttpException $e, Request $request) use ($respondError) {
            if ($request->is('api/v1/distributor-applications*')) {
                try {
                    $solicitud = $request->route('application');
                    if ($solicitud instanceof SolicitudDistribuidora && $request->user() !== null) {
                        app(AuditorSolicitudDistribuidora::class)->registrar($request->user(), $solicitud, 'DISTRIBUTOR_APPLICATION_ACCESS_DENIED', ['status' => $solicitud->status->value], [], $e->getMessage(), 'DENIED');
                    }
                } catch (Throwable $th) {
                }

                $version = str_contains(mb_strtolower($e->getMessage()), 'versión');
                $yaEnviada = str_contains(mb_strtolower($e->getMessage()), 'ya fue enviada');
                $code = $version ? 'RESOURCE_VERSION_CONFLICT' : ($yaEnviada ? 'DISTRIBUTOR_APPLICATION_ALREADY_SUBMITTED' : 'APPLICATION_TERMINAL');

                return $respondError($request, $code, $version ? 'Este registro fue modificado por otro usuario. Actualiza la información e inténtalo nuevamente.' : 'La solicitud ya terminó y no admite más modificaciones.', 409);
            }

            return $respondError($request, 'RESOURCE_VERSION_CONFLICT', 'Este registro fue modificado por otro usuario. Actualiza la información e inténtalo nuevamente.', 409);
        });

        $exceptions->renderable(function (HttpException $e, Request $request) use ($respondError) {
            if ($e->getStatusCode() === 409 && ($request->is('api/v1/credit-increase-requests*') || $request->is('api/v1/credit-lines*'))) {
                try {
                    if ($request->user()) {
                        $routeId = $request->route('solicitudId') ?? $request->route('id') ?? $request->route('linea');
                        app(AuditorIncrementos::class)->registrar('EV-CONCURRENCY', str_contains($request->path(), 'credit-lines') ? 'credit_lines' : 'credit_increase_requests', is_string($routeId) ? $routeId : null, null, $request->user(), null, [], [], $e->getMessage(), 'CONFLICT', 'v1.0.0');
                    }
                } catch (Throwable $th) {
                }
                $code = str_contains($request->path(), 'credit-lines') ? 'CREDIT_LINE_VERSION_CONFLICT' : 'CREDIT_INCREASE_REQUEST_VERSION_CONFLICT';

                return $respondError($request, $code, 'Este registro fue modificado por otro usuario. Actualiza la información e inténtalo nuevamente.', 409);
            }

            if ($e->getStatusCode() === 503) {
                return $respondError($request, 'SERVICE_UNAVAILABLE', 'El servicio no está disponible temporalmente. Inténtalo nuevamente.', 503);
            }

            if ($e->getStatusCode() === 405) {
                return $respondError($request, 'METHOD_NOT_ALLOWED', 'El método HTTP no está permitido para este recurso.', 405);
            }

            if ($e->getStatusCode() === 403) {
                return $respondError($request, 'AUTH_SCOPE_DENIED', 'No tienes permiso para acceder a este registro.', 403);
            }

            if ($e->getStatusCode() === 401) {
                return $respondError($request, 'SESSION_EXPIRED', 'Tu sesión ha expirado. Inicia sesión nuevamente.', 401);
            }

            return null;
        });

        $exceptions->renderable(function (QueryException $e, Request $request) use ($respondError) {
            if ($e->getCode() === '23505') {
                if ($request->is('api/v1/distributor-applications*')) {
                    $constraint = mb_strtolower($e->getMessage());
                    $fields = match (true) {
                        str_contains($constraint, 'app_pers_data_curp_hmac_unique') || str_contains($constraint, 'curp') => [
                            'curp' => ['La CURP ya está registrada en otra solicitud.'],
                        ],
                        str_contains($constraint, 'app_pers_data_foreign_id_unique') || str_contains($constraint, 'official_id') => [
                            'official_id_number' => ['El número de identificación ya está registrado en otra solicitud.'],
                        ],
                        str_contains($constraint, 'application_residences_one_current') => [
                            'is_current' => ['Ya existe un domicilio actual en la solicitud.'],
                        ],
                        default => [],
                    };

                    return $respondError($request, 'DISTRIBUTOR_APPLICATION_SECTION_INCOMPLETE', 'Revisa el campo indicado.', 422, $fields);
                }

                if ($request->is('api/v1/credit-increase-requests*') || $request->is('api/v1/credit-lines*')) {
                    try {
                        if ($request->user()) {
                            $routeId = $request->route('solicitudId') ?? $request->route('id') ?? $request->route('linea');
                            app(AuditorIncrementos::class)->registrar('EV-CONCURRENCY', str_contains($request->path(), 'credit-lines') ? 'credit_lines' : 'credit_increase_requests', is_string($routeId) ? $routeId : null, null, $request->user(), null, [], [], 'Violación de restricción única por concurrencia. '.$e->getMessage(), 'CONFLICT', 'v1.0.0');
                        }
                    } catch (Throwable $th) {
                    }
                    $code = str_contains($request->path(), 'credit-lines') ? 'CREDIT_LINE_VERSION_CONFLICT' : 'CREDIT_INCREASE_REQUEST_VERSION_CONFLICT';

                    return $respondError($request, $code, 'La operación fue rechazada porque otra petición concurrente ya fue procesada.', 409);
                }

                // Generic handler for unique constraint
                return $respondError($request, 'RESOURCE_VERSION_CONFLICT', 'Este registro fue modificado por otro usuario. Actualiza la información e inténtalo nuevamente.', 409);
            }
        });

        $exceptions->renderable(function (AuthenticationException $e, Request $request) use ($respondError) {
            return $respondError($request, 'SESSION_EXPIRED', 'Tu sesión ha expirado. Inicia sesión nuevamente.', 401);
        });

        $exceptions->renderable(function (ThrottleRequestsException $e, Request $request) use ($respondError) {
            return $respondError($request, 'RATE_LIMIT_EXCEEDED', 'Demasiadas solicitudes. Intente nuevamente más tarde.', 429);
        });

        $exceptions->renderable(function (ApiException $e, Request $request) use ($respondError) {
            // Already handled internally but if it falls here, use our formatter.
            return $respondError($request, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->fields, $e->details);
        });

        $exceptions->renderable(function (Throwable $e, Request $request) use ($respondError) {
            $organizationError = match (true) {
                $e instanceof OrganizationScopeDenied => ['AUTH_SCOPE_DENIED', 403, 'No tienes permiso para acceder a este registro.'],
                $e instanceof BranchNotFound => ['BRANCH_NOT_FOUND', 404, 'Sucursal no encontrada.'],
                $e instanceof AssignmentNotFound => ['ASSIGNMENT_NOT_FOUND', 404, 'Asignación no encontrada.'],
                $e instanceof BranchVersionConflict => ['RESOURCE_VERSION_CONFLICT', 409, 'Este registro fue modificado por otro usuario. Actualiza la información e inténtalo nuevamente.'],
                $e instanceof DuplicateBranchCode => ['DUPLICATE_BRANCH_CODE', 409, 'El código de sucursal ya existe.'],
                $e instanceof HeadquartersBranchProtected => ['HEADQUARTERS_BRANCH_PROTECTED', 409, 'La sucursal matriz está protegida.'],
                $e instanceof BranchHasActiveAssignments => ['BRANCH_HAS_ACTIVE_ASSIGNMENTS', 409, 'La sucursal tiene asignaciones activas.'],
                $e instanceof BranchInactive => ['BRANCH_INACTIVE', 409, 'La sucursal está inactiva.'],
                $e instanceof AssignmentAlreadyClosed => ['ASSIGNMENT_ALREADY_CLOSED', 409, 'La asignación ya está cerrada.'],
                $e instanceof DuplicateActiveAssignment => ['DUPLICATE_ACTIVE_ASSIGNMENT', 409, 'Ya existe una asignación activa.'],
                $e instanceof RoleScopeNotAllowed => ['ROLE_SCOPE_NOT_ALLOWED', 422, 'Alcance de rol no permitido.'],
                $e instanceof UserNotAssignable => ['USER_NOT_ASSIGNABLE', 422, 'El usuario no es asignable.'],
                $e instanceof InvalidOrganizationAssignment => ['INVALID_ORGANIZATION_ASSIGNMENT', 422, 'Asignación organizacional inválida.'],
                $e instanceof InvalidBranchAddress => ['INVALID_BRANCH_ADDRESS', 422, 'Dirección de sucursal inválida.'],
                $e instanceof AddressValidationUnavailable => ['SERVICE_UNAVAILABLE', 503, 'El servicio de validación no está disponible temporalmente.'],
                default => null,
            };

            if ($organizationError !== null && $request->is('api/v1/*')) {
                [$code, $status, $msg] = $organizationError;

                if ($e instanceof OrganizationScopeDenied && $request->user() !== null) {
                    try {
                        $branchId = $request->route('id') ?? $request->route('branch') ?? $request->input('branch_id') ?? $request->query('branch_id');
                        $generalManagerIds = UserRoleScope::query()
                            ->select('user_role_scopes.user_id')
                            ->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
                            ->where('roles.code', 'general_manager')
                            ->where('user_role_scopes.scope_type', 'GLOBAL')
                            ->where('user_role_scopes.status', 'ACTIVE')
                            ->whereNull('user_role_scopes.revoked_at')
                            ->pluck('user_id')
                            ->unique()
                            ->values()
                            ->all();

                        app(OrganizationEventPublisher::class)->publish(new OrganizationEvent(
                            id: Str::uuid()->toString(),
                            type: OrganizationEventType::ORGANIZATION_SCOPE_DENIED,
                            aggregateType: 'organization_scope',
                            aggregateId: is_string($branchId) ? $branchId : $request->user()->id,
                            actorId: $request->user()->id,
                            branchId: is_string($branchId) ? $branchId : null,
                            reason: $e->getMessage(),
                            details: ['method' => $request->method(), 'path' => $request->path()],
                            notifyUserIds: $generalManagerIds,
                            outcome: 'DENIED',
                        ));
                    } catch (Throwable $th) {
                    }
                }

                return $respondError($request, $code, $msg, $status);
            }

            // Fallback for everything else
            if ($request->is('api/*')) {
                return $respondError(
                    $request, 
                    'INTERNAL_ERROR', 
                    'ERROR REAL: ' . $e->getMessage() . ' en ' . $e->getFile() . ' linea ' . $e->getLine(), 
                    500
                );
            }
        });
    })->create();
