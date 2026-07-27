<?php

namespace App\Modules\Notification\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EmailDeliveryAttempt extends Model
{
    use HasUuids;

    protected $table = 'email_delivery_attempts';

    protected $fillable = [
        'email_delivery_id',
        'attempt_number',
        'started_at',
        'finished_at',
        'result',
        'provider_message_id',
        'error_code',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
