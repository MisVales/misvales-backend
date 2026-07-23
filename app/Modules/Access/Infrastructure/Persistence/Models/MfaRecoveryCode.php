<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Persists hashed recovery codes that are valid for one use only.
 *
 * @property int $id
 * @property int $user_id
 */
#[Hidden(['code_hash'])]
final class MfaRecoveryCode extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'generated_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
