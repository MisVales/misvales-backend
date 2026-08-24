<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RegistroOperacional extends Model
{
    use HasUuids;

    protected $table = 'operational_logs';

    protected $fillable = [
        'channel',
        'level',
        'event',
        'actor_id',
        'branch_id',
        'request_id',
        'correlation_id',
        'trace_id',
        'method',
        'path',
        'status_code',
        'duration_ms',
        'context',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return ['context' => 'array', 'occurred_at' => 'immutable_datetime'];
    }
}
