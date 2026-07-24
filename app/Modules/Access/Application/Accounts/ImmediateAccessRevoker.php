<?php

namespace App\Modules\Access\Application\Accounts;

use App\Models\User;
use App\Modules\Access\Domain\Authentication\TokenState;
use App\Modules\Access\Domain\Sessions\SessionState;
use App\Modules\Access\Infrastructure\Persistence\Models\AccountInvitation;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use App\Modules\Access\Infrastructure\Persistence\Models\OperationalAuthorizationToken;
use App\Modules\Access\Infrastructure\Persistence\Models\ReauthAuthorization;
use App\Modules\Access\Infrastructure\Persistence\Models\RefreshToken;
use App\Modules\Access\Infrastructure\Persistence\Models\RefreshTokenFamily;
use Illuminate\Support\Facades\Cache;

/**
 * Revokes every active access node before a critical lifecycle change returns.
 */
final class ImmediateAccessRevoker
{
    public function revoke(User $user): void
    {
        $now = now();
        $sessionIds = AuthSession::query()->where('user_id', $user->id)->pluck('id');

        RefreshToken::query()
            ->whereIn('auth_session_id', $sessionIds)
            ->where('state', TokenState::ACTIVE->value)
            ->update(['state' => TokenState::REVOKED->value, 'revoked_at' => $now]);
        RefreshTokenFamily::query()
            ->whereIn('auth_session_id', $sessionIds)
            ->where('state', SessionState::ACTIVE->value)
            ->update(['state' => SessionState::REVOKED->value, 'revoked_at' => $now]);
        AuthSession::query()
            ->where('user_id', $user->id)
            ->where('state', SessionState::ACTIVE->value)
            ->update(['state' => SessionState::REVOKED->value, 'revoked_at' => $now]);
        ReauthAuthorization::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now, 'revoked_reason' => 'ACCOUNT_ACCESS_REVOKED']);
        OperationalAuthorizationToken::query()
            ->where(function ($query) use ($user): void {
                $query->where('requester_user_id', $user->id)
                    ->orWhere('authorizer_user_id', $user->id)
                    ->orWhere('executor_user_id', $user->id);
            })
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now, 'revoked_reason' => 'ACCOUNT_ACCESS_REVOKED']);
        AccountInvitation::query()
            ->where('user_id', $user->id)
            ->where('state', TokenState::ACTIVE->value)
            ->update(['state' => TokenState::REVOKED->value, 'revoked_at' => $now]);
        $user->tokens()->delete();

        Cache::store((string) config('access.revocation_cache_store', 'array'))
            ->forever("access:user:{$user->id}:context-version", $user->context_version);
    }
}
