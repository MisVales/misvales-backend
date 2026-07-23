<?php

use App\Modules\Access\Presentation\Http\Controllers\AccountController;
use App\Modules\Access\Presentation\Http\Controllers\AccountRequestController;
use App\Modules\Access\Presentation\Http\Controllers\CredentialController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('auth/password/change', [CredentialController::class, 'change'])->name('auth.password.change');
    Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::post('accounts/{account}/disable', [AccountController::class, 'disable'])->name('accounts.disable');
    Route::post('accounts/{account}/disable-request', [AccountRequestController::class, 'disableRequest'])->name('account-requests.disable');
    Route::post('accounts/{account}/reactivate', [AccountController::class, 'reactivate'])->name('accounts.reactivate');
    Route::post('accounts/{account}/reactivate-request', [AccountRequestController::class, 'reactivateRequest'])->name('account-requests.reactivate');
    Route::post('accounts/{account}/recovery', [AccountController::class, 'recovery'])->name('accounts.recovery');
    Route::post('accounts/{account}/recovery-request', [AccountRequestController::class, 'recoveryRequest'])->name('account-requests.recovery');
    Route::post('accounts/{account}/invitation/resend', [AccountController::class, 'resend'])->name('accounts.invitation.resend');

    Route::prefix('auth/mfa')->group(function () {
        Route::post('totp/setup', [\App\Modules\Access\Presentation\Http\Controllers\TotpController::class, 'setup'])->name('auth.mfa.totp.setup');
        Route::post('totp/confirm', [\App\Modules\Access\Presentation\Http\Controllers\TotpController::class, 'confirm'])->name('auth.mfa.totp.confirm');
        Route::delete('totp', [\App\Modules\Access\Presentation\Http\Controllers\TotpController::class, 'destroy'])->name('auth.mfa.totp.destroy');
        
        Route::post('passkeys/options', [\App\Modules\Access\Presentation\Http\Controllers\PasskeyController::class, 'options'])->name('auth.mfa.passkeys.options');
        Route::post('passkeys', [\App\Modules\Access\Presentation\Http\Controllers\PasskeyController::class, 'store'])->name('auth.mfa.passkeys.store');
        Route::delete('passkeys/{credentialId}', [\App\Modules\Access\Presentation\Http\Controllers\PasskeyController::class, 'destroy'])->name('auth.mfa.passkeys.destroy');
        
        Route::post('recovery-codes/regenerate', [\App\Modules\Access\Presentation\Http\Controllers\RecoveryCodeController::class, 'regenerate'])->name('auth.mfa.recovery-codes.regenerate');
    });

    Route::get('account-requests', [AccountRequestController::class, 'index'])->name('account-requests.index');
    Route::post('account-requests', [AccountRequestController::class, 'store'])->name('account-requests.store');
    Route::post('account-requests/{accountRequest}/approve', [AccountRequestController::class, 'approve'])->name('account-requests.approve');
    Route::post('account-requests/{accountRequest}/reject', [AccountRequestController::class, 'reject'])->name('account-requests.reject');
});

Route::post('auth/invitations/inspect', [CredentialController::class, 'inspect'])->name('auth.invitations.inspect');
Route::post('auth/invitations/complete', [CredentialController::class, 'completeInvitation'])->name('auth.invitations.complete');
Route::post('auth/recovery/password', [CredentialController::class, 'requestRecovery'])->name('auth.recovery.password');
Route::post('auth/recovery/password/complete', [CredentialController::class, 'completeRecovery'])->name('auth.recovery.password.complete');

// B06 Login & MFA Verification (Unprotected)
Route::post('auth/login', [\App\Modules\Access\Presentation\Http\Controllers\LoginController::class, 'login'])->name('auth.login');
Route::prefix('auth/mfa')->group(function () {
    Route::post('webauthn/verify', [\App\Modules\Access\Presentation\Http\Controllers\MfaVerificationController::class, 'verifyPasskey'])->name('auth.mfa.passkeys.verify');
    Route::post('totp/verify', [\App\Modules\Access\Presentation\Http\Controllers\MfaVerificationController::class, 'verifyTotp'])->name('auth.mfa.totp.verify');
    Route::post('recovery-code/verify', [\App\Modules\Access\Presentation\Http\Controllers\MfaVerificationController::class, 'verifyRecoveryCode'])->name('auth.mfa.recovery-codes.verify');
});

// B07 Refresh Token
Route::post('auth/refresh', [\App\Modules\Access\Presentation\Http\Controllers\SessionController::class, 'refresh'])->name('auth.refresh');
