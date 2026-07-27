<?php

namespace App\Modules\Notification\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NotificationEvent extends Model
{
    use HasUuids;

    protected $table = 'notification_events';

    protected $fillable = [
        'outbox_event_id',
        'event_code',
        'event_version',
        'aggregate_type',
        'aggregate_id',
        'branch_id',
        'actor_user_id',
        'authorizer_user_id',
        'correlation_id',
        'causation_id',
        'occurred_at',
        'payload_snapshot',
        'processing_status',
        'last_error_code',
        'processed_at',
    ];

    protected $casts = [
        'payload_snapshot' => 'json',
        'occurred_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
