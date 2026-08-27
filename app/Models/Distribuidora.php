<?php

namespace App\Models;

use App\Enums\EstadoDistribuidora;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Distribuidora extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'distributors';

    protected $fillable = [
        'application_id',
        'user_id',
        'distributor_number',
        'branch_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => EstadoDistribuidora::class,
            'activated_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(DistributorApplication::class, 'application_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function activadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function asignacionesCoordinador(): HasMany
    {
        return $this->hasMany(CoordinatorDistributorAssignment::class, 'distributor_id');
    }

    public function coordinadorVigente(): HasOne
    {
        return $this->hasOne(CoordinatorDistributorAssignment::class, 'distributor_id')
            ->where('status', 'ACTIVE')
            ->whereNull('valid_to');
    }

    public function asignacionesCategoria(): HasMany
    {
        return $this->hasMany(AsignacionCategoriaDistribuidora::class, 'distributor_id');
    }

    public function categoriaVigente(): HasOne
    {
        return $this->hasOne(AsignacionCategoriaDistribuidora::class, 'distributor_id')
            ->where('starts_at', '<=', now())
            ->where(function ($consulta): void {
                $consulta->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->orderByDesc('starts_at');
    }

    public function lineaCredito(): HasOne
    {
        return $this->hasOne(LineaCredito::class, 'distributor_id');
    }

    public function relaciones(): HasMany
    {
        return $this->hasMany(RelacionDistribuidora::class, 'distributor_id');
    }

    public function cuentaBancariaVigente(): HasOne
    {
        return $this->hasOne(CuentaBancariaDistribuidora::class, 'distributor_id')
            ->where('is_current', true)
            ->whereNull('ends_at');
    }

    public function asignacionesClientes(): HasMany
    {
        return $this->hasMany(AsignacionClienteDistribuidora::class, 'distributor_id');
    }

    public function cuentaPuntos(): HasOne
    {
        return $this->hasOne(PointAccount::class, 'distributor_id');
    }

    public function canjesPuntos(): HasMany
    {
        return $this->hasMany(PointRedemptionRequest::class, 'distributor_id');
    }

    public function movimientosPuntos(): HasMany
    {
        return $this->hasMany(PointMovement::class, 'distributor_id');
    }

    public function archivosSolicitud(): HasMany
    {
        return $this->hasMany(MediaFileBinding::class, 'owner_id', 'application_id')
            ->where('owner_type', 'distributor_application')
            ->latest();
    }
}
