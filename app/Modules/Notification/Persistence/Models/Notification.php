<?php

namespace App\Modules\Notification\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasUuids;

    protected $table = 'notifications';

    protected $fillable = [
        'notification_event_id',
        'notification_recipient_id',
        'user_id',
        'event_code',
        'title',
        'summary',
        'template_version',
        'target_type',
        'target_id',
        'status',
        'read_at',
        'occurred_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'occurred_at' => 'datetime',
    ];
}
