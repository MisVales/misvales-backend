<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'actor_id', 'actor_role', 'branch_id', 'entity_type', 'event_name', 'entity_id',
        'version', 'previous_value', 'new_value', 'effective_from', 'effective_to',
        'reason', 'ip_address', 'user_agent', 'request_id', 'trace_id', 'result',
    ];

    protected $casts = [
        'previous_value' => 'array',
        'new_value' => 'array',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
    ];
}
