<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

class MfaRecoveryCode extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['user_id', 'code_hash', 'issued_at'];

    /** @var list<string> */
    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['issued_at' => 'immutable_datetime', 'used_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
