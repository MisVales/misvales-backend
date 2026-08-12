<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AlertaRiesgoDistribuidora extends Model
{
    use HasUuids;

    protected $table = 'distributor_risk_alerts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['relation_ids' => 'array', 'overdue_balance' => 'decimal:4', 'consecutive_defaults' => 'integer'];
    }
}
