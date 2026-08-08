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
use App\Http\Controllers\Api\V1\UserAssignmentController;
use App\Http\Controllers\Api\V1\InvitationListController;
use App\Http\Controllers\Api\V1\SecurityEventController;
use App\Http\Controllers\Api\V1\UserController;
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
        Route::get('users/{id}/assignments', [UserAssignmentController::class, 'index']);
        Route::post('users/{id}/assignments', [UserAssignmentController::class, 'store']);
        Route::delete('users/{id}/assignments/{assignmentId}', [UserAssignmentController::class, 'destroy']);

        // Módulo 2 - Sucursales y Personal
        Route::apiResource('branches', \App\Http\Controllers\Api\V1\BranchController::class)->except(['destroy']);
        Route::patch('branches/{branch}/status', [\App\Http\Controllers\Api\V1\BranchController::class, 'changeStatus']);
        
        Route::get('branches/{branch}/personnel', [\App\Http\Controllers\Api\V1\BranchPersonnelController::class, 'index']);
        Route::post('branches/{branch}/personnel', [\App\Http\Controllers\Api\V1\BranchPersonnelController::class, 'store']);
        Route::delete('branches/{branch}/personnel/{assignment}', [\App\Http\Controllers\Api\V1\BranchPersonnelController::class, 'destroy']);

        // Módulo 2 - Asignación Coordinador - Distribuidora
        Route::get('assignments/coordinator-distributor', [\App\Http\Controllers\Api\V1\CoordinatorAssignmentController::class, 'index']);
        Route::post('assignments/coordinator-distributor', [\App\Http\Controllers\Api\V1\CoordinatorAssignmentController::class, 'store']);
        Route::delete('assignments/coordinator-distributor/{assignment}', [\App\Http\Controllers\Api\V1\CoordinatorAssignmentController::class, 'destroy']);
    });
});
