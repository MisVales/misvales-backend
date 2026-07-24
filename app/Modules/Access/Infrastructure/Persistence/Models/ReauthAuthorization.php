<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Persists short-lived reauthentication grants for sensitive actions.
 */
/**
 * @property CarbonImmutable $issued_at
 * @property CarbonImmutable $expires_at
 */
#[Hidden(['token_hash'])]
final class ReauthAuthorization extends Model
{
    use HasPublicUuid;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'context_version' => 'integer',
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $authorization): void {
            $authorization->action = match ((string) $authorization->action) {
                'account.request.create' => 'account_requests.create',
                'account.request.approve' => 'account_requests.approve',
                'account.request.reject' => 'account_requests.reject',
                'account.disable' => 'accounts.disable',
                'account.reactivate' => 'accounts.reactivate',
                'account.recovery' => 'accounts.recovery',
                'account.invitation.resend' => 'accounts.invitation.resend',
                default => $authorization->action,
            };

            $user = User::query()->findOrFail($authorization->user_id);
            $authorization->requester_user_id ??= $user->id;
            $authorization->method ??= 'PASSWORD_TOTP';
            $authorization->parameters_hash ??= hash('sha256', '[]');
            $authorization->context_version ??= (int) $user->context_version;

            $branchId = $authorization->getAttribute('branch_id');
            if ($branchId !== null && ! is_string($branchId)) {
                $authorization->branch_id = $user->branch_public_id;
            }
        });
    }
}
