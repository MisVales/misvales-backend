<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

final class NotificationDelivery extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_attempt_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
