<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AlertaRiesgoDistribuidora extends Model
{
    use HasUuids;

    protected $table = 'distributor_risk_alerts';

    protected $appends = ['relation_details'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'relation_ids' => 'array',
            'overdue_balance' => 'decimal:4',
            'consecutive_defaults' => 'integer',
        ];
    }

    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class, 'distributor_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function getRelationDetailsAttribute(): array
    {
        return RelacionDistribuidora::query()
            ->with('partidas:id,relation_id,snapshot')
            ->where('distributor_id', $this->distributor_id)
            ->select('id', 'payment_reference', 'cutoff_at', 'payment_deadline_at', 'portfolio_total', 'misvales_total', 'reconciled_total', 'balance', 'financial_status', 'settled_at')
            ->latest('cutoff_at')
            ->get()
            ->each(function (RelacionDistribuidora $relation): void {
                $relation->setAttribute('distributor_profit_total', $relation->partidas->reduce(
                    fn (string $total, $item): string => bcadd($total, (string) ($item->snapshot['distributor_profit'] ?? '0'), 4),
                    '0.0000',
                ));
                $relation->unsetRelation('partidas');
            })
            ->toArray();
    }
}
