<?php

declare(strict_types=1);

use App\Modules\Payment\Presentation\Http\Controllers\PaymentCommandController;
use App\Modules\Payment\Presentation\Http\Controllers\PaymentReadController;
use Illuminate\Support\Facades\Route;

Route::middleware(['request.id', 'auth:sanctum', 'throttle:60,1'])->group(function (): void {
    Route::get('bank-imports', [PaymentReadController::class, 'bankImports'])->name('bank-imports.index');
    Route::post('bank-imports', [PaymentCommandController::class, 'receiveBankImport'])
        ->middleware('throttle:10,1')->name('bank-imports.store');
    Route::get('bank-imports/{bankImport}', [PaymentReadController::class, 'bankImport'])
        ->whereUuid('bankImport')->name('bank-imports.show');
    Route::get('bank-imports/{bankImport}/movements', [PaymentReadController::class, 'importMovements'])
        ->whereUuid('bankImport')->name('bank-imports.movements');
    Route::post('bank-imports/{bankImport}/retry', [PaymentCommandController::class, 'retryBankImport'])
        ->whereUuid('bankImport')->name('bank-imports.retry');

    Route::get('bank-movements', [PaymentReadController::class, 'bankMovements'])->name('bank-movements.index');
    Route::get('bank-movements/{bankMovement}', [PaymentReadController::class, 'bankMovement'])
        ->whereUuid('bankMovement')->name('bank-movements.show');
    Route::get('relations/{relation}/payments', [PaymentReadController::class, 'relationPayments'])
        ->where('relation', '[A-Za-z0-9_-]{1,128}')->name('relations.payments');
    Route::get('payment-allocations/{paymentAllocation}', [PaymentReadController::class, 'allocation'])
        ->whereUuid('paymentAllocation')->name('payment-allocations.show');

    Route::get('clarifications', [PaymentReadController::class, 'clarifications'])->name('clarifications.index');
    Route::post('clarifications', [PaymentCommandController::class, 'createClarification'])->name('clarifications.store');
    Route::get('clarifications/{clarification}', [PaymentReadController::class, 'clarification'])
        ->whereUuid('clarification')->name('clarifications.show');
    Route::post('clarifications/{clarification}/link-movement', [PaymentCommandController::class, 'linkMovement'])
        ->whereUuid('clarification')->name('clarifications.link-movement');
    Route::post('clarifications/{clarification}/reject', [PaymentCommandController::class, 'rejectClarification'])
        ->whereUuid('clarification')->name('clarifications.reject');

    Route::get('manual-reconciliations', [PaymentReadController::class, 'manualReconciliations'])
        ->name('manual-reconciliations.index');
    Route::post('manual-reconciliations', [PaymentCommandController::class, 'requestManualReconciliation'])
        ->name('manual-reconciliations.store');
    Route::get('manual-reconciliations/{manualReconciliation}', [PaymentReadController::class, 'manualReconciliation'])
        ->whereUuid('manualReconciliation')->name('manual-reconciliations.show');
    Route::post('manual-reconciliations/{manualReconciliation}/authorize', [PaymentCommandController::class, 'authorizeManual'])
        ->whereUuid('manualReconciliation')->name('manual-reconciliations.authorize');
    Route::post('manual-reconciliations/{manualReconciliation}/reject', [PaymentCommandController::class, 'rejectManual'])
        ->whereUuid('manualReconciliation')->name('manual-reconciliations.reject');
    Route::post('manual-reconciliations/{manualReconciliation}/apply', [PaymentCommandController::class, 'applyManual'])
        ->whereUuid('manualReconciliation')->name('manual-reconciliations.apply');

});
