<?php

use App\Modules\Access\Presentation\Http\Controllers\AccountController;
use App\Modules\Access\Presentation\Http\Controllers\AccountRequestController;
use App\Modules\Access\Presentation\Http\Controllers\ContextController;
use App\Modules\Access\Presentation\Http\Controllers\CredentialController;
use App\Modules\Access\Presentation\Http\Controllers\LoginController;
use App\Modules\Access\Presentation\Http\Controllers\MfaVerificationController;
use App\Modules\Access\Presentation\Http\Controllers\PasskeyController;
use App\Modules\Access\Presentation\Http\Controllers\ReauthenticationController;
use App\Modules\Access\Presentation\Http\Controllers\RecoveryCodeController;
use App\Modules\Access\Presentation\Http\Controllers\SecurityAlertController;
use App\Modules\Access\Presentation\Http\Controllers\SessionController;
use App\Modules\Access\Presentation\Http\Controllers\TotpController;
use App\Modules\Access\Presentation\Http\Middleware\VerifyContextVersionMiddleware;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', VerifyContextVersionMiddleware::class])->group(function (): void {
    Route::get('auth/context', [ContextController::class, 'getContext'])->name('auth.context.index');
    Route::post('auth/reauthenticate', [ReauthenticationController::class, 'store'])->name('auth.reauthenticate');
    Route::get('security/alerts', [SecurityAlertController::class, 'index'])->name('security.alerts.index');
    Route::post('security/alerts/{alert}/acknowledge', [SecurityAlertController::class, 'acknowledge'])->name('security.alerts.acknowledge');
    Route::post('security/alerts/{alert}/request-action', [SecurityAlertController::class, 'requestAction'])->name('security.alerts.request-action');

    // B09 Session Management
    Route::post('auth/logout', [SessionController::class, 'logout'])->name('auth.logout');
    Route::get('auth/sessions', [SessionController::class, 'index'])->name('auth.sessions.index');
    Route::delete('auth/sessions/others', [SessionController::class, 'destroyOthers'])->name('auth.sessions.destroyOthers');
    Route::delete('auth/sessions/{sessionId}', [SessionController::class, 'destroy'])->name('auth.sessions.destroy');

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
        Route::post('totp/setup', [TotpController::class, 'setup'])->name('auth.mfa.totp.setup');
        Route::post('totp/confirm', [TotpController::class, 'confirm'])->name('auth.mfa.totp.confirm');
        Route::delete('totp', [TotpController::class, 'destroy'])->name('auth.mfa.totp.destroy');

        Route::post('passkeys/options', [PasskeyController::class, 'options'])->name('auth.mfa.passkeys.options');
        Route::post('passkeys', [PasskeyController::class, 'store'])->name('auth.mfa.passkeys.store');
        Route::delete('passkeys/{credentialId}', [PasskeyController::class, 'destroy'])->name('auth.mfa.passkeys.destroy');

        Route::post('recovery-codes/regenerate', [RecoveryCodeController::class, 'regenerate'])->name('auth.mfa.recovery-codes.regenerate');
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
Route::post('auth/login', [LoginController::class, 'login'])->name('auth.login');
Route::prefix('auth/mfa')->group(function () {
    Route::post('webauthn/verify', [MfaVerificationController::class, 'verifyPasskey'])->name('auth.mfa.passkeys.verify');
    Route::post('totp/verify', [MfaVerificationController::class, 'verifyTotp'])->name('auth.mfa.totp.verify');
    Route::post('recovery-code/verify', [MfaVerificationController::class, 'verifyRecoveryCode'])->name('auth.mfa.recovery-codes.verify');
});

// B07 Refresh Token
Route::post('auth/refresh', [SessionController::class, 'refresh'])->name('auth.refresh');
