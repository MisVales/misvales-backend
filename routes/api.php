<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CoordinatorAssignmentController;


// Rutas del Módulo 02
Route::prefix('m02')->group(function () {
    Route::get('/assignments', [CoordinatorAssignmentController::class, 'index']);
    Route::post('/assignments', [CoordinatorAssignmentController::class, 'store']);
    Route::get('/assignments/{uuid}', [CoordinatorAssignmentController::class, 'show']);
    Route::put('/assignments/{uuid}', [CoordinatorAssignmentController::class, 'update']);
    Route::delete('/assignments/{uuid}', [CoordinatorAssignmentController::class, 'destroy']);
});


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


use App\Modules\Relation\Interfaces\Http\Controllers\CutRunController;
Route::post('v1/cut-runs', [CutRunController::class, 'store']);
