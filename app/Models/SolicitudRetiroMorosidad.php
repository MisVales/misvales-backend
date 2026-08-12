<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class SolicitudRetiroMorosidad extends Model
{
    use HasUuids;

    protected $table = 'delinquency_removal_requests';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['decided_at' => 'immutable_datetime'];
    }
}
