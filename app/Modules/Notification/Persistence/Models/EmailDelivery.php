<?php

namespace App\Modules\Notification\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EmailDelivery extends Model
{
    use HasUuids;

    protected $table = 'email_deliveries';

    protected $fillable = [
        'notification_event_id',
        'notification_recipient_id',
        'event_code',
        'recipient_email_snapshot',
        'subject_snapshot',
        'template_version',
        'render_context_snapshot',
        'message_key',
        'status',
        'attempt_count',
        'queued_at',
        'sent_at',
        'failed_at',
        'provider_message_id',
        'last_error_code',
    ];

    protected $casts = [
        'render_context_snapshot' => 'json',
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];
}
