<?php

namespace App\Modules\Notification\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NotificationRecipient extends Model
{
    use HasUuids;

    protected $table = 'notification_recipients';

    protected $fillable = [
        'notification_event_id',
        'recipient_key',
        'recipient_type',
        'user_id',
        'application_id',
        'email_snapshot',
        'role_snapshot',
        'branch_id_snapshot',
        'resolution_reasons',
        'resolved_at',
    ];

    protected $casts = [
        'resolution_reasons' => 'json',
        'resolved_at' => 'datetime',
    ];
}
