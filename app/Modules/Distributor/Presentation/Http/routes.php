<?php

use App\Modules\Distributor\Presentation\Http\Controllers\DistributorCategoryController;
use App\Modules\Distributor\Presentation\Http\Controllers\DistributorProfileController;
use App\Modules\Distributor\Presentation\Http\Controllers\DistributorQueryController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    
    // Rutas operativas y gerenciales
    Route::get('/distributors', [DistributorQueryController::class, 'index']);
    Route::get('/distributors/{id}', [DistributorQueryController::class, 'show']);
    Route::get('/distributors/{id}/history', [DistributorQueryController::class, 'history']); // Falta método en el controller, se usará el genérico de logs.
    Route::get('/distributors/{id}/category-assignments', [DistributorQueryController::class, 'categoryAssignments']);
    Route::get('/distributors/{id}/capabilities', [DistributorQueryController::class, 'capabilities']);
    
    Route::post('/distributors/{id}/category-assignments', [DistributorCategoryController::class, 'store']);
    
    // Perfil propio
    Route::get('/me/distributor-profile', [DistributorProfileController::class, 'show']);

});
