<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Modules\Access\Domain\Authentication\TokenState;
use Illuminate\Database\Eloquent\Model;

class RefreshToken extends Model
{
    /** @var list<string> */
    protected $fillable = ['refresh_token_family_id', 'auth_session_id', 'token_hash', 'state', 'issued_at', 'expires_at'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'state' => TokenState::class,
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'replaced_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
