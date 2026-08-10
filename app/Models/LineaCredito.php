<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LineaCredito extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'credit_lines';

    protected $fillable = ['distributor_id', 'total_authorized'];

    protected function casts(): array
    {
        return [
            'total_authorized' => 'decimal:4',
            'used_balance' => 'decimal:4',
            'lock_version' => 'integer',
        ];
    }

    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class, 'distributor_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoLineaCredito::class, 'credit_line_id');
    }

    public function restricciones(): HasMany
    {
        return $this->hasMany(RestriccionUsoCredito::class, 'credit_line_id');
    }

    public function saldoDisponible(): string
    {
        return bcsub($this->total_authorized, $this->used_balance, 4);
    }

    public function solicitudesIncremento(): HasMany
    {
        return $this->hasMany(SolicitudIncrementoLinea::class, 'credit_line_id');
    }
}
