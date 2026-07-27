<?php

namespace App\Modules\Audit\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProcessRun extends Model
{
    use HasUuids;

    protected $table = 'process_runs';

    protected $fillable = [
        'process_code',
        'business_identifier',
        'status',
        'attempt',
        'started_at',
        'finished_at',
        'actor_user_id',
        'branch_id',
        'request_id',
        'trace_id',
        'correlation_id',
        'summary',
        'counters',
        'error_code',
        'next_retry_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'counters' => 'json',
    ];
}
