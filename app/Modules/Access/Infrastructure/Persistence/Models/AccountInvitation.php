<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\InvitationPurpose;
use App\Modules\Access\Domain\Authentication\TokenState;
use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

class AccountInvitation extends Model
{
    use HasPublicUuid;

    /** @var list<string> */
    protected $fillable = ['public_id', 'user_id', 'purpose', 'email_hash', 'credential_version', 'token_hash', 'state', 'issued_at', 'expires_at'];

    /** @var list<string> */
    protected $hidden = ['token_hash', 'email_hash'];

    protected function casts(): array
    {
        return [
            'state' => TokenState::class,
            'purpose' => InvitationPurpose::class,
            'credential_version' => 'integer',
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $invitation): void {
            if ($invitation->email_hash !== null && $invitation->credential_version !== null) {
                return;
            }

            $user = User::query()->findOrFail($invitation->user_id);
            $invitation->email_hash ??= hash('sha256', $user->normalized_email);
            $invitation->credential_version ??= $user->credential_version;
        });
    }
}
