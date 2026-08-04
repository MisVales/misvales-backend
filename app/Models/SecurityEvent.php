<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SecurityEvent extends Model
{
    use HasUuids;

    public $timestamps = false; // Manejamos manualmente occurred_at y created_at

    protected $fillable = [
        'id',
        'user_id',
        'actor_user_id',
        'branch_id',
        'auth_session_id',
        'event_type',
        'severity',
        'outcome',
        'entity_type',
        'entity_id',
        'request_id',
        'trace_id',
        'ip_address',
        'user_agent',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
