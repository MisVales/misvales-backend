<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Modules\Access\Domain\Accounts\InvitationPurpose;
use App\Modules\Access\Domain\Authentication\TokenState;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Persists one-use invitations without exposing their readable token.
 *
 * @property int $id
 * @property int $user_id
 * @property string $email_hash
 * @property int $credential_version
 * @property InvitationPurpose $purpose
 * @property TokenState $state
 */
#[Hidden(['token_hash', 'email_hash'])]
final class AccountInvitation extends Model
{
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
}
