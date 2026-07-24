<?php

namespace App\Modules\Access\Application\Accounts;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccessRuleViolation;
use App\Modules\Access\Domain\Authorization\AuthorizationBinding;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use App\Modules\Access\Infrastructure\Persistence\Models\ReauthAuthorization;
use SensitiveParameter;

/**
 * Atomically consumes one-use, context-bound reauthentication grants.
 */
final class TemporaryAuthorization
{
    /**
     * Compatibility entry point for existing critical actions.
     *
     * @throws AccessRuleViolation
     */
    public function consumeReauth(
        User $user,
        #[SensitiveParameter] string $plainToken,
        string $action,
        string $recordId,
    ): void {
        $criticalAction = CriticalAction::tryFrom($action);
        if ($criticalAction === null) {
            throw $this->required();
        }

        $this->consume($user, $plainToken, new AuthorizationBinding(
            action: $criticalAction,
            resourceType: null,
            resourceId: $recordId,
            branchId: is_string($user->branch_id) ? $user->branch_id : null,
            parameters: [],
        ));
    }

    /**
     * Must be called inside the same database transaction as the protected change.
     *
     * @throws AccessRuleViolation
     */
    public function consume(
        User $user,
        #[SensitiveParameter] string $plainToken,
        AuthorizationBinding $binding,
    ): ReauthAuthorization {
        if ($plainToken === '') {
            throw $this->required();
        }

        $accessToken = $user->currentAccessToken();
        $sessionId = data_get($accessToken, 'auth_session_id');
        if (! is_int($sessionId)) {
            throw $this->required();
        }

        $session = AuthSession::query()
            ->whereKey($sessionId)
            ->where('user_id', $user->id)
            ->where('state', 'ACTIVE')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->lockForUpdate()
            ->first();
        if ($session === null
            || $session->context_version !== $user->context_version) {
            throw $this->required();
        }

        $authorization = ReauthAuthorization::query()
            ->where('user_id', $user->id)
            ->where('auth_session_id', $session->id)
            ->where('action', $binding->action->value)
            ->where('resource_type', $binding->resourceType)
            ->where('record_id', $binding->resourceId)
            ->where('branch_id', $binding->branchId)
            ->where('parameters_hash', $binding->parametersHash())
            ->where('context_version', $user->context_version)
            ->where('token_hash', hash('sha256', $plainToken))
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->lockForUpdate()
            ->first();

        if ($authorization === null) {
            throw $this->required();
        }

        $authorization->forceFill(['used_at' => now()])->save();

        return $authorization;
    }

    public function invalidateSession(AuthSession $session, string $reason): void
    {
        ReauthAuthorization::query()
            ->where('auth_session_id', $session->id)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoked_reason' => $reason,
                'updated_at' => now(),
            ]);
    }

    public function invalidateUser(User $user, string $reason): void
    {
        ReauthAuthorization::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoked_reason' => $reason,
                'updated_at' => now(),
            ]);
    }

    private function required(): AccessRuleViolation
    {
        return new AccessRuleViolation(
            'La acción requiere una reautenticación vigente para este contexto exacto.',
            403,
            'REAUTHENTICATION_REQUIRED',
        );
    }
}
