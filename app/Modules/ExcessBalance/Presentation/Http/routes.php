<?php

declare(strict_types=1);

use App\Modules\ExcessBalance\Presentation\Http\Controllers\ExcessBalanceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['request.id', 'auth:sanctum', 'throttle:60,1'])->group(function (): void {
    Route::prefix('me')->name('me.')->group(function (): void {
        Route::get('excess-balances', [ExcessBalanceController::class, 'index'])
            ->name('excess-balances.index');
        Route::get('excess-balances/{excessBalance}', [ExcessBalanceController::class, 'show'])
            ->whereUuid('excessBalance')->name('excess-balances.show');
        Route::post('excess-balances/{excessBalance}/credit-balance', [ExcessBalanceController::class, 'chooseCredit'])
            ->middleware('throttle:10,1')
            ->whereUuid('excessBalance')->name('excess-balances.credit-balance');
        Route::post('excess-balances/{excessBalance}/refund-requests', [ExcessBalanceController::class, 'requestRefund'])
            ->middleware('throttle:10,1')
            ->whereUuid('excessBalance')->name('excess-balances.refund-requests');
        Route::get('refund-requests', [ExcessBalanceController::class, 'refundIndex'])
            ->name('refund-requests.index');
        Route::get('refund-requests/{refundRequest}', [ExcessBalanceController::class, 'refundShow'])
            ->whereUuid('refundRequest')->name('refund-requests.show');
    });

    Route::get('excess-balances', [ExcessBalanceController::class, 'index'])
        ->name('excess-balances.index');
    Route::get('excess-balances/{excessBalance}', [ExcessBalanceController::class, 'show'])
        ->whereUuid('excessBalance')->name('excess-balances.show');
    Route::get('excess-balances/{excessBalance}/applications', [ExcessBalanceController::class, 'applications'])
        ->whereUuid('excessBalance')->name('excess-balances.applications');
    Route::get('refund-requests', [ExcessBalanceController::class, 'refundIndex'])
        ->name('refund-requests.index');
    Route::get('refund-requests/{refundRequest}', [ExcessBalanceController::class, 'refundShow'])
        ->whereUuid('refundRequest')->name('refund-requests.show');
    Route::post('refund-requests/{refundRequest}/authorize', [ExcessBalanceController::class, 'authorizeRefund'])
        ->middleware('throttle:10,1')
        ->whereUuid('refundRequest')->name('refund-requests.authorize');
    Route::post('refund-requests/{refundRequest}/reject', [ExcessBalanceController::class, 'rejectRefund'])
        ->middleware('throttle:10,1')
        ->whereUuid('refundRequest')->name('refund-requests.reject');
    Route::post('refund-requests/{refundRequest}/complete', [ExcessBalanceController::class, 'completeRefund'])
        ->middleware('throttle:10,1')
        ->whereUuid('refundRequest')->name('refund-requests.complete');
    Route::get('refund-requests/{refundRequest}/evidence', [ExcessBalanceController::class, 'evidence'])
        ->middleware('throttle:10,1')
        ->whereUuid('refundRequest')->name('refund-requests.evidence');
});
