<?php

use App\Modules\Access\Presentation\Http\Controllers\AccountController;
use App\Modules\Access\Presentation\Http\Controllers\AccountRequestController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::post('accounts/{account}/disable', [AccountController::class, 'disable'])->name('accounts.disable');
    Route::post('accounts/{account}/disable-request', [AccountRequestController::class, 'disableRequest'])->name('account-requests.disable');
    Route::post('accounts/{account}/reactivate', [AccountController::class, 'reactivate'])->name('accounts.reactivate');
    Route::post('accounts/{account}/reactivate-request', [AccountRequestController::class, 'reactivateRequest'])->name('account-requests.reactivate');
    Route::post('accounts/{account}/recovery', [AccountController::class, 'recovery'])->name('accounts.recovery');
    Route::post('accounts/{account}/recovery-request', [AccountRequestController::class, 'recoveryRequest'])->name('account-requests.recovery');
    Route::post('accounts/{account}/invitation/resend', [AccountController::class, 'resend'])->name('accounts.invitation.resend');

    Route::get('account-requests', [AccountRequestController::class, 'index'])->name('account-requests.index');
    Route::post('account-requests', [AccountRequestController::class, 'store'])->name('account-requests.store');
    Route::post('account-requests/{accountRequest}/approve', [AccountRequestController::class, 'approve'])->name('account-requests.approve');
    Route::post('account-requests/{accountRequest}/reject', [AccountRequestController::class, 'reject'])->name('account-requests.reject');
});
