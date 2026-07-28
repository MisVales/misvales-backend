<?php

declare(strict_types=1);

use App\Modules\Voucher\Presentation\Http\Controllers\ModificationRequestController;
use App\Modules\Voucher\Presentation\Http\Controllers\VoucherController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function (): void {
    Route::post('/vouchers', [VoucherController::class, 'store'])
        ->middleware('throttle:voucher-generate')
        ->name('vouchers.store');
    Route::get('/vouchers', [VoucherController::class, 'index'])->name('vouchers.index');
    Route::get('/vouchers/{voucher}', [VoucherController::class, 'show'])
        ->whereUuid('voucher')
        ->name('vouchers.show');
    Route::post('/vouchers/{voucher}/open-at-counter', [VoucherController::class, 'open'])
        ->whereUuid('voucher')
        ->middleware('throttle:voucher-open')
        ->name('vouchers.open-at-counter');
    Route::post('/vouchers/{voucher}/release', [VoucherController::class, 'release'])
        ->whereUuid('voucher')
        ->name('vouchers.release');
    Route::post('/vouchers/{voucher}/reject', [VoucherController::class, 'reject'])
        ->whereUuid('voucher')
        ->name('vouchers.reject');
    Route::post('/vouchers/{voucher}/fulfill', [VoucherController::class, 'fulfill'])
        ->whereUuid('voucher')
        ->middleware('throttle:voucher-fulfillment')
        ->name('vouchers.fulfill');
    Route::post('/vouchers/{voucher}/modification-requests', [VoucherController::class, 'requestModification'])
        ->whereUuid('voucher')
        ->middleware('throttle:voucher-modification-request')
        ->name('vouchers.modification-requests.store');

    Route::get('/modification-requests', [ModificationRequestController::class, 'index'])
        ->name('modification-requests.index');
    Route::get('/modification-requests/{modificationRequest}', [ModificationRequestController::class, 'show'])
        ->whereUuid('modificationRequest')
        ->name('modification-requests.show');
    Route::post('/modification-requests/{modificationRequest}/authorize', [ModificationRequestController::class, 'authorizeRequest'])
        ->whereUuid('modificationRequest')
        ->middleware('throttle:voucher-authorization')
        ->name('modification-requests.authorize');
    Route::post('/modification-requests/{modificationRequest}/reject', [ModificationRequestController::class, 'rejectRequest'])
        ->whereUuid('modificationRequest')
        ->middleware('throttle:voucher-authorization')
        ->name('modification-requests.reject');
    Route::post('/modification-requests/{modificationRequest}/apply', [ModificationRequestController::class, 'apply'])
        ->whereUuid('modificationRequest')
        ->middleware('throttle:voucher-token-attempt')
        ->name('modification-requests.apply');
});
