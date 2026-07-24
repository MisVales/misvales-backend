<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

class OperationalAuthorizationToken extends Model
{
    use HasPublicUuid;

    /** @var list<string> */
    protected $fillable = ['requested_by', 'authorized_by', 'executed_by', 'action', 'record_type', 'record_id', 'authorized_fields', 'branch_id', 'token_hash', 'issued_at', 'expires_at'];

    /** @var list<string> */
    protected $hidden = ['authorized_fields', 'token_hash'];

    protected function casts(): array
    {
        return [
            'authorized_fields' => 'array',
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
