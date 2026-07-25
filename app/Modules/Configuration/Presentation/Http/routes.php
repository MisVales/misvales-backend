<?php

declare(strict_types=1);

/**
 * M03 — Configuraciones y Catálogos — Rutas API.
 */

use App\Modules\Configuration\Presentation\Http\Controllers\CategoryController;
use App\Modules\Configuration\Presentation\Http\Controllers\CategoryVersionController;
use App\Modules\Configuration\Presentation\Http\Controllers\ConfigurationController;
use App\Modules\Configuration\Presentation\Http\Controllers\ConfigurationVersionController;
use App\Modules\Configuration\Presentation\Http\Controllers\ProductController;
use App\Modules\Configuration\Presentation\Http\Controllers\ProductVersionController;
use App\Modules\Configuration\Presentation\Http\Controllers\RedemptionPeriodController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // ---------------------------------------------------------
    // Configuraciones (C02, C03)
    // ---------------------------------------------------------
    Route::get('configurations', [ConfigurationController::class, 'index']);
    
    Route::prefix('configurations/{key}/versions')->group(function () {
        Route::get('/', [ConfigurationVersionController::class, 'index']);
        Route::post('/', [ConfigurationVersionController::class, 'store']);
        Route::put('{publicId}', [ConfigurationVersionController::class, 'update']);
        Route::post('{publicId}/publish', [ConfigurationVersionController::class, 'publish']);
        Route::post('{publicId}/deactivate', [ConfigurationVersionController::class, 'deactivate']);
    });

    // ---------------------------------------------------------
    // Categorías (C08)
    // ---------------------------------------------------------
    Route::get('categories', [CategoryController::class, 'index']);
    Route::post('categories', [CategoryController::class, 'store']);
    Route::post('categories/{publicId}/deactivate', [CategoryController::class, 'deactivate']);

    Route::prefix('categories/{publicId}/versions')->group(function () {
        Route::get('/', [CategoryVersionController::class, 'index']);
        Route::post('/', [CategoryVersionController::class, 'store']);
        Route::put('{versionPublicId}', [CategoryVersionController::class, 'update']);
        Route::post('{versionPublicId}/publish', [CategoryVersionController::class, 'publish']);
    });

    // ---------------------------------------------------------
    // Productos (C09)
    // ---------------------------------------------------------
    Route::get('products', [ProductController::class, 'index']);
    Route::post('products', [ProductController::class, 'store']);
    Route::post('products/{publicId}/deactivate', [ProductController::class, 'deactivate']);

    Route::prefix('products/{publicId}/versions')->group(function () {
        Route::get('/', [ProductVersionController::class, 'index']);
        Route::post('/', [ProductVersionController::class, 'store']);
        Route::put('{versionPublicId}', [ProductVersionController::class, 'update']);
        Route::post('{versionPublicId}/publish', [ProductVersionController::class, 'publish']);
    });

    // ---------------------------------------------------------
    // Periodos de Canje (C10)
    // ---------------------------------------------------------
    Route::prefix('redemption-periods')->group(function () {
        Route::get('/', [RedemptionPeriodController::class, 'index']);
        Route::post('/', [RedemptionPeriodController::class, 'store']);
        Route::put('{publicId}', [RedemptionPeriodController::class, 'update']);
        Route::post('{publicId}/publish', [RedemptionPeriodController::class, 'publish']);
        Route::post('{publicId}/deactivate', [RedemptionPeriodController::class, 'deactivate']);
    });
});
