<?php

namespace App\Modules\Access\Application\Accounts;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccessRuleViolation;
use App\Modules\Access\Infrastructure\Persistence\Models\ReauthAuthorization;
use SensitiveParameter;

/**
 * Consumes one-use reauthentication tokens for sensitive account actions.
 */
final class TemporaryAuthorization
{
    /**
     * @throws AccessRuleViolation When the reauthentication token is absent, expired, or already used.
     */
    public function consumeReauth(User $user, #[SensitiveParameter] string $plainToken, string $action, string $recordId): void
    {
        $authorization = ReauthAuthorization::query()
            ->where('user_id', $user->id)
            ->where('action', $action)
            ->where('record_id', $recordId)
            ->where('token_hash', hash('sha256', $plainToken))
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->lockForUpdate()
            ->first();

        if ($authorization === null) {
            throw new AccessRuleViolation('La reautenticación no es válida.', 403);
        }

        $authorization->forceFill(['used_at' => now()])->save();
    }
}
