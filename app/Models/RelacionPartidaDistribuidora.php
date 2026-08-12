<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RelacionPartidaDistribuidora extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'distributor_relation_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'portfolio_amount' => 'decimal:4', 'misvales_amount' => 'decimal:4'];
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(ParcialidadVale::class, 'voucher_installment_id');
    }
}
