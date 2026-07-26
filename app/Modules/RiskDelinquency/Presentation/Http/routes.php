<?php

declare(strict_types=1);

use App\Modules\RiskDelinquency\Presentation\Http\Controllers\RemovalRequestController;
use App\Modules\RiskDelinquency\Presentation\Http\Controllers\RiskAlertController;
use App\Modules\RiskDelinquency\Presentation\Http\Controllers\RiskProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('risk/distributors', [RiskProfileController::class, 'index'])->name('risk.distributors.index');
    Route::get('risk/distributors/{distributor}', [RiskProfileController::class, 'show'])
        ->whereUuid('distributor')->name('risk.distributors.show');
    Route::get('risk/distributors/{distributor}/evaluations', [RiskProfileController::class, 'evaluations'])
        ->whereUuid('distributor')->name('risk.distributors.evaluations');
    Route::get('risk/distributors/{distributor}/sequence', [RiskProfileController::class, 'sequence'])
        ->whereUuid('distributor')->name('risk.distributors.sequence');
    Route::get('risk/distributors/{distributor}/alerts', [RiskProfileController::class, 'alerts'])
        ->whereUuid('distributor')->name('risk.distributors.alerts');
    Route::get('risk/alerts/{alert}', [RiskAlertController::class, 'show'])
        ->whereUuid('alert')->name('risk.alerts.show');
    Route::get('risk/alerts/{alert}/review', [RiskAlertController::class, 'review'])
        ->whereUuid('alert')->name('risk.alerts.review');
    Route::post('risk/alerts/{alert}/apply-delinquency', [RiskAlertController::class, 'apply'])
        ->whereUuid('alert')->middleware('throttle:risk-critical')->name('risk.alerts.apply-delinquency');

    Route::get('delinquency/removal-requests', [RemovalRequestController::class, 'index'])
        ->name('delinquency.removal-requests.index');
    Route::get('delinquency/removal-requests/{removalRequest}', [RemovalRequestController::class, 'show'])
        ->whereUuid('removalRequest')->name('delinquency.removal-requests.show');
    Route::post('delinquency/distributors/{distributor}/removal-requests', [RemovalRequestController::class, 'prepare'])
        ->whereUuid('distributor')->middleware('throttle:risk-critical')->name('delinquency.removal-requests.prepare');
    Route::post('delinquency/removal-requests/{removalRequest}/approve', [RemovalRequestController::class, 'approve'])
        ->whereUuid('removalRequest')->middleware('throttle:risk-critical')->name('delinquency.removal-requests.approve');
    Route::post('delinquency/removal-requests/{removalRequest}/reject', [RemovalRequestController::class, 'reject'])
        ->whereUuid('removalRequest')->middleware('throttle:risk-critical')->name('delinquency.removal-requests.reject');
});
