<?php

namespace App\Modules\Access\Application\Authentication;

use App\Models\User;
use App\Modules\Access\Application\Accounts\AccountSecurityRecorder;
use App\Modules\Access\Domain\Accounts\AccessRuleViolation;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\MFA\MfaType;
use App\Modules\Access\Infrastructure\Persistence\Models\MfaCredential;
use App\Modules\Access\Infrastructure\Redis\LoginAttemptTracker;
use App\Modules\Access\Infrastructure\Redis\MfaSessionManager;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * Handles two-stage authentication: email/password validation and MFA verification.
 *
 * Per spec B06:
 * - Stage 1: Email and password validation creates a 5-minute MFA transaction
 * - Stage 2: MFA verification (via separate endpoints) completes login
 * - Valid password does NOT create a session; only complete auth does
 * - Transient state stored in Redis; survives network retries
 * - Generic responses prevent enumeration attacks
 */
final readonly class LoginService
{
    public function __construct(
        private AccountSecurityRecorder $recorder,
        private MfaSessionManager $mfaSessions,
        private LoginAttemptTracker $attempts,
    ) {}

    /**
     * Stage 1: Validate email and password.
     *
     * Creates a time-limited MFA transaction in Redis if credentials valid.
     * Returns an auth token for Stage 2 (MFA verification).
     *
     * @param  string  $email  User email
     * @param  string  $password  User password
     * @return array{auth_token: string, expires_at: string, mfa_required: bool}
     *
     * @throws AccessRuleViolation If authentication fails or account is inactive
     */
    public function authenticate(#[SensitiveParameter] string $email, #[SensitiveParameter] string $password): array
    {
        $normalized = mb_strtolower(trim($email));
        $user = User::query()->where('normalized_email', $normalized)->first();

        if (! $this->hasValidPassword($user, $password) || ! $this->canAttemptMfa($user)) {
            $failedUserId = $user instanceof User ? $user->id : 0;
            $this->attempts->recordFailure($failedUserId, 'unknown', 'unknown', 'password');
            $this->recorder->audit('AUTHENTICATION_PASSWORD_FAILED', 'DENIED', null, $user);

            throw new AccessRuleViolation($this->genericFailureResponse(), 401);
        }

        $allowedFactors = MfaCredential::query()
            ->where('user_id', $user->id)
            ->where('state', 'ACTIVE')
            ->pluck('type')
            ->filter(fn (mixed $type): bool => is_string($type) && MfaType::tryFrom($type) !== null)
            ->values()
            ->all();

        if ($allowedFactors === []) {
            throw new AccessRuleViolation($this->genericFailureResponse(), 401);
        }

        $session = $this->mfaSessions->createSession($user, 'administrativa', 'unknown', 'unknown', $allowedFactors);
        $this->recorder->audit('AUTHENTICATION_PASSWORD_ACCEPTED', 'MFA_REQUIRED', $user, $user);

        return [
            'auth_token' => $session['auth_token'],
            'expires_at' => $session['expires_at'],
            'mfa_required' => true,
        ];
    }

    /**
     * Get public response for failed login (prevents enumeration).
     * Per spec B06: Generic message regardless of failure reason.
     */
    public function genericFailureResponse(): string
    {
        return 'No fue posible iniciar sesión con la información proporcionada.';
    }

    private function hasValidPassword(?User $user, #[SensitiveParameter] string $password): bool
    {
        if ($user === null || $user->password === null) {
            return false;
        }

        return Hash::check(trim($password), $user->password);
    }

    private function canAttemptMfa(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $state = AccountState::tryFrom((string) $user->getRawOriginal('state'));

        return $state === AccountState::ACTIVE && $user->mfa_enrolled_at !== null;
    }
}
