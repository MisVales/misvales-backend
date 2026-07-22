<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Modules\Access\Domain\Authentication\TokenState;
use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

class AccountInvitation extends Model
{
    use HasPublicUuid;

    /** @var list<string> */
    protected $fillable = ['user_id', 'purpose', 'token_hash', 'state', 'issued_at', 'expires_at'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'state' => TokenState::class,
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
