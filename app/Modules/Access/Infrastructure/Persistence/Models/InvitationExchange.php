<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores the short-lived exchange state used while an invitation is completed.
 *
 * @property int $id
 * @property int $account_invitation_id
 */
#[Hidden(['token_hash'])]
final class InvitationExchange extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'prepared_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
