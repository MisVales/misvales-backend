<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SolicitudDevolucionExcedente extends Model
{
    use HasUuids;

    protected $table = 'surplus_refund_requests';

    protected $fillable = [
        'surplus_id', 'branch_id', 'amount', 'status', 'requested_by', 'decided_by',
        'decision_reason', 'decided_at', 'authorized_at', 'executed_by', 'cancelled_by',
        'cancellation_reason', 'cancelled_at', 'execution_method', 'execution_reference',
        'execution_amount', 'execution_observations', 'evidence_media_id', 'executed_at',
    ];

    protected $attributes = ['status' => 'REQUESTED'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4', 'execution_amount' => 'decimal:4',
            'decided_at' => 'immutable_datetime', 'authorized_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime', 'executed_at' => 'immutable_datetime',
        ];
    }

    public function surplus(): BelongsTo
    {
        return $this->belongsTo(ExcedenteDistribuidora::class, 'surplus_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decisionMaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'evidence_media_id');
    }
}
