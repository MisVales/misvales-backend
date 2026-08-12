<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class BloqueoOperativoDistribuidora extends Model
{
    use HasUuids;

    protected $table = 'distributor_operational_blocks';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }
}
