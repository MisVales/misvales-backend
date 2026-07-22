<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Modules\Access\Domain\MFA\MfaType;
use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

class MfaCredential extends Model
{
    use HasPublicUuid;

    /** @var list<string> */
    protected $fillable = ['user_id', 'type', 'credential_identifier', 'public_key', 'encrypted_secret', 'metadata', 'state'];

    /** @var list<string> */
    protected $hidden = ['public_key', 'encrypted_secret', 'metadata'];

    protected function casts(): array
    {
        return [
            'type' => MfaType::class,
            'encrypted_secret' => 'encrypted',
            'metadata' => 'array',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
