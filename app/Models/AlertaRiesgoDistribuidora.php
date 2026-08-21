<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AlertaRiesgoDistribuidora extends Model
{
    use HasUuids;

    protected $table = 'distributor_risk_alerts';

    protected $appends = ['relation_details'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['relation_ids' => 'array', 'overdue_balance' => 'decimal:4', 'consecutive_defaults' => 'integer'];
    }

    public function getRelationDetailsAttribute(): array
    {
        if (empty($this->relation_ids)) {
            return [];
        }

        return RelacionDistribuidora::query()
            ->whereIn('id', $this->relation_ids)
            ->select('id', 'payment_reference', 'cutoff_at', 'payment_deadline_at', 'misvales_total', 'balance', 'financial_status', 'settled_at')
            ->get()
            ->toArray();
    }
}
