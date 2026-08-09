<?php

use App\Http\Controllers\Api\V1\ActivacionDistribuidoraController;
use App\Http\Controllers\Api\V1\AsignacionCategoriaDistribuidoraController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\InvitationController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\CarteraInformativaClienteController;
use App\Http\Controllers\Api\V1\CategoriaController;
use App\Http\Controllers\Api\V1\ClienteController;
use App\Http\Controllers\Api\V1\ConfiguracionController;
use App\Http\Controllers\Api\V1\CoordinatorAssignmentController;
use App\Http\Controllers\Api\V1\CuentaBancariaClienteController;
use App\Http\Controllers\Api\V1\DistribuidoraController;
use App\Http\Controllers\Api\V1\InvitationListController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PeriodoCanjeController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\ProductoController;
use App\Http\Controllers\Api\V1\ReenvioInvitacionDistribuidoraController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SecurityController;
use App\Http\Controllers\Api\V1\SecurityEventController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\VerificacionDistribuidora\AutorizacionSolicitudController;
use App\Http\Controllers\VerificacionDistribuidora\CorreccionSolicitudController;
use App\Http\Controllers\VerificacionDistribuidora\EvaluacionSolicitudController;
use App\Http\Controllers\VerificacionDistribuidora\EvidenciaVerificacionController;
use App\Http\Controllers\VerificacionDistribuidora\VerificacionDistribuidoraController;
use App\Modules\Organization\Presentation\Http\Controllers\BranchAssignmentController;
use App\Modules\Organization\Presentation\Http\Controllers\BranchController;
use App\Modules\Organization\Presentation\Http\Controllers\BranchPersonnelController;
use App\Modules\Organization\Presentation\Http\Controllers\PersonnelController;
use App\Modules\Organization\Presentation\Http\Controllers\UserAssignmentCommandController;
use App\Modules\Organization\Presentation\Http\Controllers\UserAssignmentQueryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('invitations/inspect', [InvitationController::class, 'inspect'])->middleware('throttle:inspect_invitation');
        Route::post('invitations/resend', [InvitationController::class, 'resend'])->middleware('throttle:resend_invitation');
        Route::post('invitations/setup', [InvitationController::class, 'setup']);
        Route::post('invitations/passkey/setup', [InvitationController::class, 'passkeySetup']);
        Route::post('invitations/passkey/register', [InvitationController::class, 'passkeyRegister']);
        Route::post('invitations/complete', [InvitationController::class, 'complete']);

        Route::post('login', [AuthController::class, 'login']); // Protegido manual por el controlador y el servicio ciego
        Route::post('mfa/totp/verify', [AuthController::class, 'verifyTotp'])->middleware('throttle:totp');
        Route::post('mfa/passkey/options', [AuthController::class, 'passkeyOptions'])->middleware('throttle:totp');
        Route::post('mfa/passkey/verify', [AuthController::class, 'passkeyVerify'])->middleware('throttle:totp');
        Route::post('mfa/recovery-code/verify', [AuthController::class, 'verifyRecoveryCode'])->middleware('throttle:recovery_code');
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::post('password/forgot', [ForgotPasswordController::class, 'forgotPassword'])->middleware('throttle:forgot_password');
        Route::post('password/reset', [ResetPasswordController::class, 'resetPassword']);

        // Rutas protegidas de la API (Zero Trust Layer)
        Route::middleware(['auth:sanctum', 'track.activity', 'active.user', 'mfa.completed'])->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
        });
    });

    // Perfil y Permisos
    Route::middleware(['auth:sanctum', 'track.activity', 'active.user', 'mfa.completed'])->group(function () {
        Route::get('me', [MeController::class, 'show']);

        // Gestión de Sesiones (Puntos 23, 24, 25)
        Route::get('me/sessions', [SessionController::class, 'index']);
        Route::delete('me/sessions', [SessionController::class, 'destroyOther']);
        Route::delete('me/sessions/{id}', [SessionController::class, 'destroy']);

        // Seguridad y Configuraciones de Cuenta (Puntos 30, 31, 32)
        Route::post('me/security/password', [SecurityController::class, 'changePassword']);
        Route::post('me/security/recovery-codes', [SecurityController::class, 'regenerateRecoveryCodes']);
        Route::get('me/security/totp/setup', [SecurityController::class, 'totpSetup']);
        Route::post('me/security/totp/validate-current', [SecurityController::class, 'validateCurrentTotp']);
        Route::post('me/security/totp/confirm', [SecurityController::class, 'totpConfirm']);
        Route::get('me/security/passkeys', [SecurityController::class, 'passkeys']);
        Route::post('me/security/passkeys/options', [SecurityController::class, 'passkeyOptions']);
        Route::post('me/security/passkeys/register', [SecurityController::class, 'passkeyRegister']);
        Route::delete('me/security/passkeys/{id}', [SecurityController::class, 'deletePasskey']);

        // Gestión de Usuarios (Punto 33)
        Route::apiResource('users', UserController::class)->except(['destroy']);
        Route::post('users/{id}/invite', [UserController::class, 'invite'])->middleware('throttle:resend_invitation');
        Route::post('users/{id}/block', [UserController::class, 'block']);
        Route::post('users/{id}/unblock', [UserController::class, 'unblock']);
        Route::post('users/{id}/disable', [UserController::class, 'disable']);
        Route::post('users/{id}/enable', [UserController::class, 'enable']);
        Route::post('users/{id}/require-password-change', [UserController::class, 'requirePasswordChange']);

        // Roles y Permisos (Punto 34)
        Route::get('permissions', [PermissionController::class, 'index']);
        Route::get('roles', [RoleController::class, 'index']);
        Route::get('roles/{id}', [RoleController::class, 'show']);
        Route::put('roles/{id}/permissions', [RoleController::class, 'syncPermissions']);

        // Auditoría
        Route::get('security-events', [SecurityEventController::class, 'index']);

        // Invitaciones
        Route::get('invitations', [InvitationListController::class, 'index']);

        // Asignaciones Jerárquicas (Punto 35)
        Route::get('users/{id}/assignments', [UserAssignmentQueryController::class, 'index']);
        Route::post('users/{id}/assignments', [UserAssignmentCommandController::class, 'store']);
        Route::patch('users/{id}/assignments/{assignmentId}', [UserAssignmentCommandController::class, 'update']);
        Route::delete('users/{id}/assignments/{assignmentId}', [UserAssignmentCommandController::class, 'destroy']);

        // Módulo 2 - Sucursales y Personal
        Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
        Route::post('branches', [BranchController::class, 'store'])->name('branches.store');
        Route::get('branches/{id}', [BranchController::class, 'show'])->name('branches.show');
        Route::match(['put', 'patch'], 'branches/{id}', [BranchController::class, 'update'])->name('branches.update');
        Route::post('branches/{id}/activate', [BranchController::class, 'activate']);
        Route::post('branches/{id}/deactivate', [BranchController::class, 'deactivate']);
        Route::patch('branches/{id}/status', [BranchController::class, 'changeStatus']);

        Route::get('branches/{id}/personnel', [BranchPersonnelController::class, 'index']);
        Route::get('branches/{id}/assignments', [BranchAssignmentController::class, 'index']);
        Route::get('personnel', [PersonnelController::class, 'index']);

        // Módulo 2 - Asignación Coordinador - Distribuidora
        Route::get('assignments/distributors', [CoordinatorAssignmentController::class, 'distributors']);
        Route::get('assignments/coordinator-distributor', [CoordinatorAssignmentController::class, 'index']);
        Route::post('assignments/coordinator-distributor', [CoordinatorAssignmentController::class, 'store']);
        Route::delete('assignments/coordinator-distributor/{assignment}', [CoordinatorAssignmentController::class, 'destroy']);

        // Módulo 03 - Configuraciones y Catálogos
        // Configuraciones
        Route::get('configurations', [ConfiguracionController::class, 'index']);
        Route::post('configurations', [ConfiguracionController::class, 'store']);
        Route::get('configurations/{key}', [ConfiguracionController::class, 'show']);
        Route::get('configurations/{key}/versions', [ConfiguracionController::class, 'getVersionsByKey']);
        Route::post('configurations/{key}/versions', [ConfiguracionController::class, 'storeVersionByKey']);
        Route::get('configuration-versions/{id}', [ConfiguracionController::class, 'showVersion']);
        Route::patch('configuration-versions/{id}', [ConfiguracionController::class, 'updateVersion']);
        Route::post('configuration-versions/{id}/publish', [ConfiguracionController::class, 'publishVersion']);
        Route::post('configuration-versions/{id}/deactivate', [ConfiguracionController::class, 'deactivateVersion']);

        // Categorías
        Route::get('categories', [CategoriaController::class, 'index']);
        Route::post('categories', [CategoriaController::class, 'store']);
        Route::get('categories/{id}', [CategoriaController::class, 'show']);
        Route::get('categories/{id}/versions', [CategoriaController::class, 'getVersions']);
        Route::post('categories/{id}/versions', [CategoriaController::class, 'storeVersion']);
        Route::get('category-versions/{id}', [CategoriaController::class, 'showVersion']);
        Route::patch('category-versions/{id}', [CategoriaController::class, 'updateVersion']);
        Route::post('category-versions/{id}/publish', [CategoriaController::class, 'publishVersion']);
        Route::post('categories/{id}/deactivate', [CategoriaController::class, 'deactivateCategory']);

        // Productos
        Route::get('products', [ProductoController::class, 'index']);
        Route::post('products', [ProductoController::class, 'store']);
        Route::get('products/{id}', [ProductoController::class, 'show']);
        Route::get('products/{id}/versions', [ProductoController::class, 'getVersions']);
        Route::post('products/{id}/versions', [ProductoController::class, 'storeVersion']);
        Route::get('product-versions/{id}', [ProductoController::class, 'showVersion']);
        Route::patch('product-versions/{id}', [ProductoController::class, 'updateVersion']);
        Route::post('product-versions/{id}/publish', [ProductoController::class, 'publishVersion']);
        Route::post('products/{id}/deactivate', [ProductoController::class, 'deactivateProduct']);

        // Periodos de canje
        Route::get('redemption-periods', [PeriodoCanjeController::class, 'index']);
        Route::post('redemption-periods', [PeriodoCanjeController::class, 'store']);
        Route::get('redemption-periods/{id}', [PeriodoCanjeController::class, 'show']);
        Route::patch('redemption-periods/{id}', [PeriodoCanjeController::class, 'update']);
        Route::post('redemption-periods/{id}/publish', [PeriodoCanjeController::class, 'publish']);
        Route::post('redemption-periods/{id}/cancel', [PeriodoCanjeController::class, 'cancel']);

        // Módulo 5 - Verificación y autorización formal de distribuidoras
        Route::get('distributor-applications', [VerificacionDistribuidoraController::class, 'index'])
            ->middleware('permission:verification.applications.view');
        Route::get('distributor-applications/{application}', [VerificacionDistribuidoraController::class, 'show'])
            ->middleware('permission:verification.applications.view');
        Route::get('distributor-applications/{application}/available-verifiers', [VerificacionDistribuidoraController::class, 'verificadoresDisponibles'])
            ->middleware('permission:verification.verifiers.assign');
        Route::post('distributor-applications/{application}/assign-verifier', [VerificacionDistribuidoraController::class, 'asignarVerificador'])
            ->middleware('permission:verification.verifiers.assign');

        Route::get('verification-visits/assigned', [VerificacionDistribuidoraController::class, 'consultarAsignadas'])
            ->middleware('permission:verification.visits.view');
        Route::get('verification-visits/{visit}', [VerificacionDistribuidoraController::class, 'consultarVisita'])
            ->middleware('permission:verification.visits.view');
        Route::post('verification-visits/{visit}/start', [VerificacionDistribuidoraController::class, 'iniciarVisita'])
            ->middleware('permission:verification.visits.perform');
        Route::put('verification-visits/{visit}', [VerificacionDistribuidoraController::class, 'actualizarVisita'])
            ->middleware('permission:verification.visits.perform');
        Route::post('verification-visits/{visit}/finish', [VerificacionDistribuidoraController::class, 'finalizarVisita'])
            ->middleware('permission:verification.visits.perform');

        Route::post('verification-visits/{visit}/evidences', [EvidenciaVerificacionController::class, 'adjuntarEvidencia'])
            ->middleware(['permission:verification.evidences.manage', 'throttle:30,1']);
        Route::get('verification-visits/{visit}/evidences/{evidence}/download', [EvidenciaVerificacionController::class, 'descargarEvidencia'])
            ->middleware('permission:verification.visits.view');
        Route::delete('verification-visits/{visit}/evidences/{evidence}', [EvidenciaVerificacionController::class, 'eliminarEvidenciaAbierta'])
            ->middleware('permission:verification.evidences.manage');

        Route::post('distributor-applications/{application}/corrections', [CorreccionSolicitudController::class, 'aplicarCorreccion'])
            ->middleware('permission:verification.corrections.manage');
        Route::post('distributor-applications/{application}/corrections/finish', [CorreccionSolicitudController::class, 'finalizarCorrecciones'])
            ->middleware('permission:verification.corrections.manage');
        Route::post('distributor-applications/{application}/evaluate', [EvaluacionSolicitudController::class, 'evaluar'])
            ->middleware('permission:verification.evaluations.decide');
        Route::post('distributor-applications/{application}/authorize', [AutorizacionSolicitudController::class, 'autorizar'])
            ->middleware('permission:verification.authorizations.decide');

        // Módulo 6 - Activación y administración de distribuidoras
        Route::post('distributor-applications/{application}/activation', [ActivacionDistribuidoraController::class, 'store'])
            ->middleware(['permission:distributors.activate', 'idempotency']);
        Route::get('distributors', [DistribuidoraController::class, 'index'])
            ->middleware('permission:distributors.view_any');
        Route::get('distributors/{distributor}', [DistribuidoraController::class, 'show'])
            ->middleware('permission:distributors.view');
        Route::get('distributors/{distributor}/category-assignments', [AsignacionCategoriaDistribuidoraController::class, 'index'])
            ->middleware('permission:distributors.view_category_history');
        Route::post('distributors/{distributor}/category-assignments', [AsignacionCategoriaDistribuidoraController::class, 'store'])
            ->middleware('permission:distributors.assign_category');
        Route::post('distributors/{distributor}/activation-invitations/resend', [ReenvioInvitacionDistribuidoraController::class, 'store'])
            ->middleware(['permission:distributors.resend_activation', 'throttle:resend_invitation']);

        // Módulo 7 - Clientes finales y cartera informativa
        Route::get('clients', [ClienteController::class, 'index'])->middleware('permission:clients.view');
        Route::post('clients', [ClienteController::class, 'store'])->middleware(['permission:clients.create', 'idempotency']);
        Route::get('clients/{client}', [ClienteController::class, 'show'])->middleware('permission:clients.view');
        Route::get('clients/{client}/bank-accounts', [CuentaBancariaClienteController::class, 'index'])
            ->middleware('permission:clients.view_bank_accounts');
        Route::post('clients/{client}/bank-accounts', [CuentaBancariaClienteController::class, 'store'])
            ->middleware('permission:clients.manage_bank_accounts');
        Route::get('clients/{client}/portfolio-entries', [CarteraInformativaClienteController::class, 'index'])
            ->middleware('permission:clients.view_portfolio');
        Route::post('clients/{client}/portfolio-entries', [CarteraInformativaClienteController::class, 'store'])
            ->middleware(['permission:clients.manage_portfolio', 'idempotency']);
        Route::patch('clients/{client}/portfolio-entries/{entry}', [CarteraInformativaClienteController::class, 'update'])
            ->middleware('permission:clients.manage_portfolio');
    });
});
