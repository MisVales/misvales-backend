<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Modules\Access\Domain\Sessions\SessionState;
use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

class AuthSession extends Model
{
    use HasPublicUuid;

    /** @var list<string> */
    protected $fillable = ['user_id', 'application', 'device_id', 'device_name', 'ip_address', 'user_agent', 'last_activity_at', 'expires_at', 'state', 'context_version'];

    /** @var list<string> */
    protected $hidden = ['device_id', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return [
            'state' => SessionState::class,
            'version' => 'integer',
            'context_version' => 'integer',
            'last_activity_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
