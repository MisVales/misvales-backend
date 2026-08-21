<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AclaracionPago extends Model
{
    use HasUuids;

    protected $table = 'payment_clarifications';

    protected $fillable = [
        'folio',
        'distributor_id',
        'relation_id',
        'evidence_media_id',
        'created_by',
        'reason',
        'status',
    ];

    public function relation(): BelongsTo
    {
        return $this->belongsTo(RelacionDistribuidora::class, 'relation_id');
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class, 'distributor_id');
    }
}
