<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SolicitudTransferenciaCliente extends Model
{
    use HasUuids;

    protected $table = 'client_transfer_requests';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'preaccepted_at' => 'immutable_datetime',
            'origin_decided_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
