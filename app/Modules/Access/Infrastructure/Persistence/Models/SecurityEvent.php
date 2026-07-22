<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

class SecurityEvent extends Model
{
    use HasPublicUuid;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['actor_user_id', 'target_user_id', 'auth_session_id', 'rule_code', 'scope', 'result', 'correlation_id', 'metadata', 'occurred_at'];

    /** @var list<string> */
    protected $hidden = ['metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'immutable_datetime'];
    }
}
