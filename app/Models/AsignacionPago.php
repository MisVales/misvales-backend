<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AsignacionPago extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'payment_allocations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function partidaRelacion(): BelongsTo
    {
        return $this->belongsTo(RelacionPartidaDistribuidora::class, 'relation_item_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Vale::class, 'voucher_id');
    }
}
