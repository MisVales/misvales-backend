<?php

namespace App\Models;

use App\Enums\EstadoRestriccionUsoCredito;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestriccionUsoCredito extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'credit_usage_restrictions';

    protected $fillable = ['credit_line_id', 'type', 'base_total'];

    protected function casts(): array
    {
        return [
            'status' => EstadoRestriccionUsoCredito::class,
            'base_total' => 'decimal:4',
            'consumed_at' => 'immutable_datetime',
        ];
    }

    public function lineaCredito(): BelongsTo
    {
        return $this->belongsTo(LineaCredito::class, 'credit_line_id');
    }
}
