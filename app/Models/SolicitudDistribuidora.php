<?php

namespace App\Models;

use App\Enums\EstadoSolicitudDistribuidora;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SolicitudDistribuidora extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'distributor_applications';

    protected $fillable = ['branch_id', 'coordinator_id', 'section_declarations', 'lock_version'];

    protected function casts(): array
    {
        return [
            'status' => EstadoSolicitudDistribuidora::class,
            'section_declarations' => 'array',
            'submitted_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(BranchRecord::class, 'branch_id');
    }

    public function coordinador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function remitente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function datosPersonales(): HasOne
    {
        return $this->hasOne(DatosPersonalesSolicitud::class, 'application_id');
    }

    public function familiares(): HasMany
    {
        return $this->hasMany(FamiliarSolicitud::class, 'application_id');
    }

    public function domicilios(): HasMany
    {
        return $this->hasMany(DomicilioSolicitud::class, 'application_id');
    }

    public function vehiculos(): HasMany
    {
        return $this->hasMany(VehiculoSolicitud::class, 'application_id');
    }

    public function patrimonio(): HasMany
    {
        return $this->hasMany(PatrimonioSolicitud::class, 'application_id');
    }

    public function empleos(): HasMany
    {
        return $this->hasMany(EmpleoSolicitud::class, 'application_id');
    }

    public function creditosComerciales(): HasMany
    {
        return $this->hasMany(CreditoComercialSolicitud::class, 'application_id');
    }

    public function verificationVisits(): HasMany
    {
        return $this->hasMany(VerificationVisit::class, 'application_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(ApplicationCorrection::class, 'application_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(ApplicationEvaluation::class, 'application_id');
    }

    public function latestEvaluation(): HasOne
    {
        return $this->hasOne(ApplicationEvaluation::class, 'application_id')
            ->orderByDesc('evaluated_at')
            ->orderByDesc('created_at');
    }

    public function authorization(): HasOne
    {
        return $this->hasOne(ApplicationAuthorization::class, 'application_id');
    }
}
