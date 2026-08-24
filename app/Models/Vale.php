<?php

namespace App\Models;

use App\Enums\EstadoVale;
use App\Enums\TipoVale;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Vale extends Model
{
    use HasUuids;

    protected $table = 'vouchers';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => TipoVale::class,
            'status' => EstadoVale::class,
            'financial_snapshot' => 'array',
            'generated_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'cashed_at' => 'immutable_datetime',
            'capital' => 'decimal:4',
            'loan_commission_percentage' => 'decimal:6',
            'loan_commission_amount' => 'decimal:4',
            'simple_interest_percentage' => 'decimal:6',
            'insurance_amount' => 'decimal:4',
            'interest_total' => 'decimal:4',
            'misvales_total' => 'decimal:4',
            'misvales_payment_per_fortnight' => 'decimal:4',
            'distributor_profit_percentage' => 'decimal:6',
            'distributor_profit_total' => 'decimal:4',
            'distributor_profit_per_fortnight' => 'decimal:4',
            'client_payment_per_fortnight' => 'decimal:4',
            'client_total' => 'decimal:4',
            'fortnights_count' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    public function parcialidades(): HasMany
    {
        return $this->hasMany(ParcialidadVale::class, 'voucher_id')->orderBy('number');
    }

    public function solicitudesModificacion(): HasMany
    {
        return $this->hasMany(SolicitudModificacionVale::class, 'voucher_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'client_id');
    }

    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class, 'distributor_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function versionProducto(): BelongsTo
    {
        return $this->belongsTo(ProductVersion::class, 'product_version_id');
    }

    public function versionCategoria(): BelongsTo
    {
        return $this->belongsTo(CategoryVersion::class, 'category_version_id');
    }

    public function lineaCredito(): BelongsTo
    {
        return $this->belongsTo(LineaCredito::class, 'credit_line_id');
    }
}
