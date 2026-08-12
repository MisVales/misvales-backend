<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class SolicitudCanjePuntos extends Model
{
    use HasUuids;

    protected $table = 'point_redemption_requests';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['points' => 'integer', 'point_value_snapshot' => 'decimal:4', 'monetary_value' => 'decimal:4', 'delivered_at' => 'immutable_datetime'];
    }
}
