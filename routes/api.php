<?php

use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CoordinatorAssignmentController;
use App\Http\Controllers\Api\OrganizationUserController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserRoleScopeController;
use App\Modules\Relation\Interfaces\Http\Controllers\CutRunController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('m02')->group(function () {

    // ==========================================
    // ASIGNACIONES (Coordinador - Distribuidora)
    // ==========================================
    // Gestiona la relación jerárquica vigente entre coordinadores y distribuidoras.
    // Garantiza que ambos pertenezcan a la misma sucursal y que no haya duplicados.
    Route::get('/assignments', [CoordinatorAssignmentController::class, 'index']);       // Lista asignaciones según el alcance del usuario (Global o Local).
    Route::post('/assignments', [CoordinatorAssignmentController::class, 'store']);      // Crea una nueva asignación inicial autorizada.
    Route::get('/assignments/{uuid}', [CoordinatorAssignmentController::class, 'show']); // Consulta el detalle de una asignación permitida.
    Route::put('/assignments/{uuid}', [CoordinatorAssignmentController::class, 'update']); // Actualiza/Reasigna a una distribuidora cerrando la vigencia anterior.
    Route::delete('/assignments/{uuid}', [CoordinatorAssignmentController::class, 'destroy']); // Cierra una asignación (solo si el flujo lo permite).

    // ==========================================
    // ALCANCES ORGANIZACIONALES (Scopes)
    // ==========================================
    // Administra los contextos de los usuarios (si son de alcance GLOBAL o de SUCURSAL).
    Route::get('/scopes', [UserRoleScopeController::class, 'index']); // Historial y vigencias de alcances.
    Route::post('/scopes', [UserRoleScopeController::class, 'store']); // Asigna un alcance organizacional (Exclusivo de Gerente General).

    // ==========================================
    // SUCURSALES (Branches)
    // ==========================================
    Route::get('/branches', [BranchController::class, 'index']); // Lista sucursales. GG ve todas, perfiles locales solo ven la suya.
    Route::get('/branches/{uuid}', [BranchController::class, 'show']); // Consulta datos mínimos de una sucursal permitida.

    // ==========================================
    // ROLES Y PERMISOS
    // ==========================================
    // Catálogo base e inmutable de los perfiles operativos del sistema.
    Route::get('/roles', [RoleController::class, 'index']); // Consulta el catálogo visible de roles del sistema.
    Route::get('/roles/{id}', [RoleController::class, 'show']); // Consulta un rol específico y sus permisos.
    Route::put('/roles/{id}/permissions', [RoleController::class, 'updatePermissions']); // Actualiza permisos e invalida sesiones
    Route::get('/permissions', [PermissionController::class, 'index']); // Catálogo de permisos

    // ==========================================
    // DIRECTORIO DE USUARIOS (Nuevo)
    // ==========================================
    // Fuente única de verdad para consultar "Quién es quién" en la organización.
    // Protege hashes, tokens y aplica aislamiento estricto de sucursal.
    Route::get('/users', [OrganizationUserController::class, 'index']); // Lista usuarios. GG ve todos, GS ve su sucursal, perfiles operativos son rechazados.
    Route::get('/users/{uuid}', [OrganizationUserController::class, 'show']); // Consulta el perfil organizacional de un usuario dentro del alcance.
});

// Rutas base de M01 (Acceso / Autenticación)
Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('app/Modules/Access/Presentation/Http/routes.php'));

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('app/Modules/Configuration/Presentation/Http/routes.php'));

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('app/Modules/Credit/Presentation/Http/routes.php'));

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('app/Modules/DistributorOnboarding/Presentation/Http/routes.php'));

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('app/Modules/Client/Presentation/Http/routes.php'));

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('app/Modules/Voucher/Presentation/Http/routes.php'));

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('app/Modules/Payment/Presentation/Http/routes.php'));

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('app/Modules/ExcessBalance/Presentation/Http/routes.php'));

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('app/Modules/Points/Presentation/Http/routes.php'));

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('app/Modules/RiskDelinquency/Presentation/Http/routes.php'));

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('app/Modules/Mobility/Presentation/Http/routes.php'));

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('app/Modules/Reporting/Presentation/Http/routes.php'));

Route::post('v1/cut-runs', [CutRunController::class, 'store']);

require base_path('app/Modules/Distributor/Presentation/Http/routes.php');
require base_path('app/Modules/Notification/Presentation/Http/routes.php');
require base_path('app/Modules/Audit/Presentation/Http/routes.php');
require base_path('app/Modules/Media/Presentation/Http/routes.php');
