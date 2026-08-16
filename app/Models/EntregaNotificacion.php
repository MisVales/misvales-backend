<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EntregaNotificacion extends Model
{
    use HasUuids;

    protected $table = 'notification_deliveries';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }
}
