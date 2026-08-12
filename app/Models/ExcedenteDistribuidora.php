<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ExcedenteDistribuidora extends Model
{
    use HasUuids;

    protected $table = 'distributor_surpluses';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['original_amount' => 'decimal:4', 'available_amount' => 'decimal:4', 'reserved_amount' => 'decimal:4'];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class, 'distributor_id');
    }
}
