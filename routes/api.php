<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\InvitationController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SecurityController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\SolicitudDistribuidoraController;
use App\Http\Controllers\Api\V1\UserController;
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
        Route::post('invitations/setup', [InvitationController::class, 'setup']);
        Route::post('invitations/complete', [InvitationController::class, 'complete']);

        Route::post('login', [AuthController::class, 'login']); // Protegido manual por el controlador y el servicio ciego
        Route::post('mfa/totp/verify', [AuthController::class, 'verifyTotp'])->middleware('throttle:totp');
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
        Route::post('me/security/totp/confirm', [SecurityController::class, 'totpConfirm']);

        // Gestión de Usuarios (Punto 33)
        Route::apiResource('users', UserController::class)->except(['destroy']);
        Route::post('users/{id}/invite', [UserController::class, 'invite'])->middleware('throttle:resend_invitation');
        Route::post('users/{id}/block', [UserController::class, 'block']);
        Route::post('users/{id}/unblock', [UserController::class, 'unblock']);
        Route::post('users/{id}/disable', [UserController::class, 'disable']);

        // Roles y Permisos (Punto 34)
        Route::get('permissions', [PermissionController::class, 'index']);
        Route::get('roles', [RoleController::class, 'index']);
        Route::get('roles/{id}', [RoleController::class, 'show']);
        Route::put('roles/{id}/permissions', [RoleController::class, 'syncPermissions']);

        // Asignaciones Jerárquicas (Punto 35)
        Route::get('users/{id}/assignments', [UserAssignmentQueryController::class, 'index']);
        Route::post('users/{id}/assignments', [UserAssignmentCommandController::class, 'store']);
        Route::patch('users/{id}/assignments/{assignmentId}', [UserAssignmentCommandController::class, 'update']);
        Route::delete('users/{id}/assignments/{assignmentId}', [UserAssignmentCommandController::class, 'destroy']);

        // Organización y sucursales
        Route::get('branches', [BranchController::class, 'index']);
        Route::post('branches', [BranchController::class, 'store']);
        Route::get('branches/{id}', [BranchController::class, 'show']);
        Route::patch('branches/{id}', [BranchController::class, 'update']);
        Route::post('branches/{id}/activate', [BranchController::class, 'activate']);
        Route::post('branches/{id}/deactivate', [BranchController::class, 'deactivate']);
        Route::get('branches/{id}/personnel', [BranchPersonnelController::class, 'index']);
        Route::get('branches/{id}/assignments', [BranchAssignmentController::class, 'index']);
        Route::get('personnel', [PersonnelController::class, 'index']);

        // Solicitudes de distribuidoras
        Route::middleware('branch.scope')->group(function (): void {
            Route::get('distributor-applications', [SolicitudDistribuidoraController::class, 'index'])
                ->middleware('permission:distributor_applications.view');
            Route::post('distributor-applications', [SolicitudDistribuidoraController::class, 'store'])
                ->middleware('permission:distributor_applications.create');
            Route::get('distributor-applications/{application}', [SolicitudDistribuidoraController::class, 'show'])
                ->middleware('permission:distributor_applications.view');
            Route::patch('distributor-applications/{application}', [SolicitudDistribuidoraController::class, 'update'])
                ->middleware('permission:distributor_applications.update');
            Route::post('distributor-applications/{application}/submit', [SolicitudDistribuidoraController::class, 'enviarARevision'])
                ->middleware('permission:distributor_applications.submit');
            Route::put('distributor-applications/{application}/personal-data', [SolicitudDistribuidoraController::class, 'guardarDatosPersonales'])
                ->middleware('permission:distributor_applications.update');
            Route::get('distributor-applications/{application}/residences', [SolicitudDistribuidoraController::class, 'listarDomicilios'])
                ->middleware('permission:distributor_applications.view');
            Route::post('distributor-applications/{application}/residences', [SolicitudDistribuidoraController::class, 'crearDomicilio'])
                ->middleware('permission:distributor_applications.update');
            Route::patch('distributor-applications/{application}/residences/{residence}', [SolicitudDistribuidoraController::class, 'actualizarDomicilio'])
                ->middleware('permission:distributor_applications.update');
            Route::delete('distributor-applications/{application}/residences/{residence}', [SolicitudDistribuidoraController::class, 'eliminarDomicilio'])
                ->middleware('permission:distributor_applications.update');

            Route::get('distributor-applications/{application}/family-members', [SolicitudDistribuidoraController::class, 'listarFamiliares'])->middleware('permission:distributor_applications.view');
            Route::post('distributor-applications/{application}/family-members', [SolicitudDistribuidoraController::class, 'crearFamiliar'])->middleware('permission:distributor_applications.update');
            Route::patch('distributor-applications/{application}/family-members/{member}', [SolicitudDistribuidoraController::class, 'actualizarFamiliar'])->middleware('permission:distributor_applications.update');
            Route::delete('distributor-applications/{application}/family-members/{member}', [SolicitudDistribuidoraController::class, 'eliminarFamiliar'])->middleware('permission:distributor_applications.update');

            Route::get('distributor-applications/{application}/vehicles', [SolicitudDistribuidoraController::class, 'listarVehiculos'])->middleware('permission:distributor_applications.view');
            Route::post('distributor-applications/{application}/vehicles', [SolicitudDistribuidoraController::class, 'crearVehiculo'])->middleware('permission:distributor_applications.update');
            Route::patch('distributor-applications/{application}/vehicles/{vehicle}', [SolicitudDistribuidoraController::class, 'actualizarVehiculo'])->middleware('permission:distributor_applications.update');
            Route::delete('distributor-applications/{application}/vehicles/{vehicle}', [SolicitudDistribuidoraController::class, 'eliminarVehiculo'])->middleware('permission:distributor_applications.update');

            Route::get('distributor-applications/{application}/assets-liabilities', [SolicitudDistribuidoraController::class, 'listarPatrimonio'])->middleware('permission:distributor_applications.view');
            Route::post('distributor-applications/{application}/assets-liabilities', [SolicitudDistribuidoraController::class, 'crearPatrimonio'])->middleware('permission:distributor_applications.update');
            Route::patch('distributor-applications/{application}/assets-liabilities/{entry}', [SolicitudDistribuidoraController::class, 'actualizarPatrimonio'])->middleware('permission:distributor_applications.update');
            Route::delete('distributor-applications/{application}/assets-liabilities/{entry}', [SolicitudDistribuidoraController::class, 'eliminarPatrimonio'])->middleware('permission:distributor_applications.update');

            Route::get('distributor-applications/{application}/employments', [SolicitudDistribuidoraController::class, 'listarEmpleos'])->middleware('permission:distributor_applications.view');
            Route::post('distributor-applications/{application}/employments', [SolicitudDistribuidoraController::class, 'crearEmpleo'])->middleware('permission:distributor_applications.update');
            Route::patch('distributor-applications/{application}/employments/{employment}', [SolicitudDistribuidoraController::class, 'actualizarEmpleo'])->middleware('permission:distributor_applications.update');
            Route::delete('distributor-applications/{application}/employments/{employment}', [SolicitudDistribuidoraController::class, 'eliminarEmpleo'])->middleware('permission:distributor_applications.update');

            Route::get('distributor-applications/{application}/commercial-credits', [SolicitudDistribuidoraController::class, 'listarCreditosComerciales'])->middleware('permission:distributor_applications.view');
            Route::post('distributor-applications/{application}/commercial-credits', [SolicitudDistribuidoraController::class, 'crearCreditoComercial'])->middleware('permission:distributor_applications.update');
            Route::patch('distributor-applications/{application}/commercial-credits/{credit}', [SolicitudDistribuidoraController::class, 'actualizarCreditoComercial'])->middleware('permission:distributor_applications.update');
            Route::delete('distributor-applications/{application}/commercial-credits/{credit}', [SolicitudDistribuidoraController::class, 'eliminarCreditoComercial'])->middleware('permission:distributor_applications.update');
        });
    });
});
