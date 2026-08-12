<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class SolicitudDevolucionExcedente extends Model
{
    use HasUuids;

    protected $table = 'surplus_refund_requests';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'executed_at' => 'immutable_datetime'];
    }
}
