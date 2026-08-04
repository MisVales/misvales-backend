<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Auth\InvitationController;

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('invitations/inspect', [\App\Http\Controllers\Api\V1\Auth\InvitationController::class, 'inspect'])->middleware('throttle:inspect_invitation');
        Route::post('invitations/setup', [\App\Http\Controllers\Api\V1\Auth\InvitationController::class, 'setup']);
        Route::post('invitations/complete', [\App\Http\Controllers\Api\V1\Auth\InvitationController::class, 'complete']);
        
        Route::post('login', [\App\Http\Controllers\Api\V1\Auth\AuthController::class, 'login']); // Protegido manual por el controlador y el servicio ciego
        Route::post('mfa/totp/verify', [\App\Http\Controllers\Api\V1\Auth\AuthController::class, 'verifyTotp'])->middleware('throttle:totp');
        Route::post('mfa/recovery-code/verify', [\App\Http\Controllers\Api\V1\Auth\AuthController::class, 'verifyRecoveryCode'])->middleware('throttle:recovery_code');
        Route::post('refresh', [\App\Http\Controllers\Api\V1\Auth\AuthController::class, 'refresh']);
        Route::post('password/forgot', [\App\Http\Controllers\Api\V1\Auth\ForgotPasswordController::class, 'forgotPassword'])->middleware('throttle:forgot_password');
        Route::post('password/reset', [\App\Http\Controllers\Api\V1\Auth\ResetPasswordController::class, 'resetPassword']);
        
        // Rutas protegidas de la API (Zero Trust Layer)
        Route::middleware(['auth:sanctum', 'track.activity', 'active.user', 'mfa.completed'])->group(function () {
            Route::post('logout', [\App\Http\Controllers\Api\V1\Auth\AuthController::class, 'logout']);
        });
    });

    // Perfil y Permisos
    Route::middleware(['auth:sanctum', 'track.activity', 'active.user', 'mfa.completed'])->group(function () {
        Route::get('me', [\App\Http\Controllers\Api\V1\MeController::class, 'show']);
        
        // Gestión de Sesiones (Puntos 23, 24, 25)
        Route::get('me/sessions', [\App\Http\Controllers\Api\V1\SessionController::class, 'index']);
        Route::delete('me/sessions', [\App\Http\Controllers\Api\V1\SessionController::class, 'destroyOther']);
        Route::delete('me/sessions/{id}', [\App\Http\Controllers\Api\V1\SessionController::class, 'destroy']);
        
        // Seguridad y Configuraciones de Cuenta (Puntos 30, 31, 32)
        Route::post('me/security/password', [\App\Http\Controllers\Api\V1\SecurityController::class, 'changePassword']);
        Route::post('me/security/recovery-codes', [\App\Http\Controllers\Api\V1\SecurityController::class, 'regenerateRecoveryCodes']);
        Route::get('me/security/totp/setup', [\App\Http\Controllers\Api\V1\SecurityController::class, 'totpSetup']);
        Route::post('me/security/totp/confirm', [\App\Http\Controllers\Api\V1\SecurityController::class, 'totpConfirm']);
        
        // Gestión de Usuarios (Punto 33)
        Route::apiResource('users', \App\Http\Controllers\Api\V1\UserController::class)->except(['destroy']);
        Route::post('users/{id}/invite', [\App\Http\Controllers\Api\V1\UserController::class, 'invite'])->middleware('throttle:resend_invitation');
        Route::post('users/{id}/block', [\App\Http\Controllers\Api\V1\UserController::class, 'block']);
        Route::post('users/{id}/unblock', [\App\Http\Controllers\Api\V1\UserController::class, 'unblock']);
        Route::post('users/{id}/disable', [\App\Http\Controllers\Api\V1\UserController::class, 'disable']);
        
        // Roles y Permisos (Punto 34)
        Route::get('permissions', [\App\Http\Controllers\Api\V1\PermissionController::class, 'index']);
        Route::get('roles', [\App\Http\Controllers\Api\V1\RoleController::class, 'index']);
        Route::get('roles/{id}', [\App\Http\Controllers\Api\V1\RoleController::class, 'show']);
        Route::put('roles/{id}/permissions', [\App\Http\Controllers\Api\V1\RoleController::class, 'syncPermissions']);
        
        // Asignaciones Jerárquicas (Punto 35)
        Route::get('users/{id}/assignments', [\App\Http\Controllers\Api\V1\UserAssignmentController::class, 'index']);
        Route::post('users/{id}/assignments', [\App\Http\Controllers\Api\V1\UserAssignmentController::class, 'store']);
        Route::delete('users/{id}/assignments/{assignmentId}', [\App\Http\Controllers\Api\V1\UserAssignmentController::class, 'destroy']);
    });
});
