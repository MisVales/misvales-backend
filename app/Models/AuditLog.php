<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'actor_id', 'actor_role', 'branch_id', 'entity_type', 'event_name', 'entity_id',
        'version', 'previous_value', 'new_value', 'effective_from', 'effective_to',
        'reason', 'ip_address', 'user_agent', 'request_id', 'trace_id', 'result',
        'authorizer_id', 'executor_id', 'session_id', 'correlation_id', 'evidence', 'rule_snapshot',
    ];

    protected $casts = [
        'previous_value' => 'array',
        'new_value' => 'array',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'evidence' => 'array',
        'rule_snapshot' => 'array',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
