<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\InvitationPurpose;
use App\Modules\Access\Domain\Authentication\TokenState;
use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Persists one-use invitations without exposing their readable token.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $email_hash
 * @property int|null $credential_version
 * @property InvitationPurpose $purpose
 * @property TokenState $state
 * @property CarbonImmutable $expires_at
 */
#[Hidden(['token_hash', 'email_hash'])]
final class AccountInvitation extends Model
{
    use HasPublicUuid;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'purpose' => InvitationPurpose::class,
            'state' => TokenState::class,
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $invitation): void {
            if ($invitation->email_hash !== null && $invitation->credential_version !== null) {
                return;
            }

            $user = User::query()->findOrFail($invitation->user_id);
            $invitation->email_hash ??= hash('sha256', (string) $user->normalized_email);
            $invitation->credential_version ??= (int) $user->credential_version;
        });
    }
}
