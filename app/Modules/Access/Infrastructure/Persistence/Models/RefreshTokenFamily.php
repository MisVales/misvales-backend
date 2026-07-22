<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Modules\Access\Domain\Sessions\SessionState;
use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

class RefreshTokenFamily extends Model
{
    use HasPublicUuid;

    /** @var list<string> */
    protected $fillable = ['auth_session_id', 'application', 'state', 'absolute_expires_at'];

    protected function casts(): array
    {
        return ['state' => SessionState::class, 'absolute_expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
