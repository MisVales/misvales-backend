<?php

declare(strict_types=1);

use App\Modules\Points\Presentation\Http\Controllers\PointAccountController;
use App\Modules\Points\Presentation\Http\Controllers\PointRedemptionController;
use App\Modules\Points\Presentation\Http\Controllers\PointsRunController;
use App\Modules\Points\Presentation\Http\Controllers\RedemptionPeriodController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('me/points', [PointAccountController::class, 'me'])->name('me.points.show');
    Route::get('me/points/movements', [PointAccountController::class, 'myMovements'])->name('me.points.movements');
    Route::get('distributors/{distributor}/points', [PointAccountController::class, 'show'])->whereUuid('distributor')->name('distributors.points.show');
    Route::get('distributors/{distributor}/points/movements', [PointAccountController::class, 'movements'])->whereUuid('distributor')->name('distributors.points.movements');
    Route::get('relations/{relation}/points', [PointAccountController::class, 'relation'])->whereUuid('relation')->name('relations.points.show');

    Route::get('point-redemption-periods/current', [RedemptionPeriodController::class, 'current'])->name('point-redemption-periods.current');
    Route::get('point-redemption-periods', [RedemptionPeriodController::class, 'index'])->name('point-redemption-periods.index');
    Route::post('point-redemption-periods', [RedemptionPeriodController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('point-redemption-periods.store');
    Route::get('point-redemption-periods/{period}', [RedemptionPeriodController::class, 'show'])->whereUuid('period')->name('point-redemption-periods.show');
    Route::post('point-redemption-periods/{period}/publish', [RedemptionPeriodController::class, 'publish'])
        ->whereUuid('period')
        ->middleware('throttle:6,1')
        ->name('point-redemption-periods.publish');

    Route::get('me/point-redemptions', [PointRedemptionController::class, 'mine'])->name('me.point-redemptions.index');
    // POST /me/point-redemptions permanece deliberadamente sin ruta: cantidad total/parcial no definida.
    Route::get('point-redemptions', [PointRedemptionController::class, 'index'])->name('point-redemptions.index');
    Route::get('point-redemptions/{redemption}', [PointRedemptionController::class, 'show'])->whereUuid('redemption')->name('point-redemptions.show');
    Route::post('point-redemptions/{redemption}/authorize', [PointRedemptionController::class, 'authorize'])
        ->whereUuid('redemption')
        ->middleware('throttle:10,1')
        ->name('point-redemptions.authorize');
    Route::post('point-redemptions/{redemption}/reject', [PointRedemptionController::class, 'reject'])
        ->whereUuid('redemption')
        ->middleware('throttle:10,1')
        ->name('point-redemptions.reject');
    // POST /point-redemptions/{id}/complete no se publica hasta definir el rol ejecutor.

    Route::get('points-runs', [PointsRunController::class, 'index'])->name('points-runs.index');
    Route::get('points-runs/{run}', [PointsRunController::class, 'show'])->whereUuid('run')->name('points-runs.show');
    Route::get('points-runs/{run}/items', [PointsRunController::class, 'items'])->whereUuid('run')->name('points-runs.items');
});
