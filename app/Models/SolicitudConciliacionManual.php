<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SolicitudConciliacionManual extends Model
{
    use HasUuids;

    protected $table = 'manual_reconciliation_requests';

    protected $fillable = [
        'bank_movement_id',
        'relation_id',
        'clarification_id',
        'branch_id',
        'reason',
        'status',
        'requested_by',
        'authorized_by',
        'decision_reason',
        'decided_at',
        'executed_by',
        'before_snapshot',
        'after_snapshot',
        'authorized_at',
        'executed_at',
    ];

    protected function casts(): array
    {
        return ['before_snapshot' => 'array', 'after_snapshot' => 'array', 'decided_at' => 'immutable_datetime', 'authorized_at' => 'immutable_datetime', 'executed_at' => 'immutable_datetime'];
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(MovimientoBancario::class, 'bank_movement_id');
    }

    public function relation(): BelongsTo
    {
        return $this->belongsTo(RelacionDistribuidora::class, 'relation_id');
    }

    public function clarification(): BelongsTo
    {
        return $this->belongsTo(AclaracionPago::class, 'clarification_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function authorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }
}
