<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MovimientoBancario extends Model
{
    use HasUuids;

    protected $table = 'bank_movements';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['original_row' => 'array', 'errors' => 'array', 'amount' => 'decimal:4', 'applied_amount' => 'decimal:4', 'surplus_amount' => 'decimal:4', 'paid_at' => 'immutable_datetime'];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(ImportacionArchivoBancario::class, 'import_id');
    }
}
