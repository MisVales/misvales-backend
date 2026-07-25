<?php

declare(strict_types=1);

use App\Modules\Access\Presentation\Http\Middleware\VerifyContextVersionMiddleware;
use App\Modules\Credit\Presentation\Http\Controllers\CreditIncreaseController;
use App\Modules\Credit\Presentation\Http\Controllers\CreditLineController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', VerifyContextVersionMiddleware::class])->group(function (): void {
    Route::get('distributors/{distributor}/credit-line', [CreditLineController::class, 'show'])
        ->name('distributors.credit-line.show');
    Route::get('distributors/{distributor}/credit-line/movements', [CreditLineController::class, 'movements'])
        ->name('distributors.credit-line.movements');
    Route::post('distributors/{distributor}/credit-increase-requests', [CreditIncreaseController::class, 'store'])
        ->name('credit-increase-requests.store');
    Route::get('credit-increase-requests', [CreditIncreaseController::class, 'index'])
        ->name('credit-increase-requests.index');
    Route::get('credit-increase-requests/{creditIncreaseRequest}', [CreditIncreaseController::class, 'show'])
        ->name('credit-increase-requests.show');
    Route::post('credit-increase-requests/{creditIncreaseRequest}/preauthorize', [CreditIncreaseController::class, 'review'])
        ->name('credit-increase-requests.review');
    Route::post('credit-increase-requests/{creditIncreaseRequest}/manager-decision', [CreditIncreaseController::class, 'managerDecision'])
        ->name('credit-increase-requests.manager-decision');
});
