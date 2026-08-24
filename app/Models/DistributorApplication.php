<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Exceptions\BusinessException;
use App\Models\Concerns\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;

class DistributorApplication extends Model
{
    use HasFactory;
    use HasOptimisticLocking, HasUuids;

    protected $fillable = [
        'application_number',
        'branch_id',
        'coordinator_id',
        'status',
        'section_declarations',
        'pending_sections',
        'created_by',
        'submitted_by',
        'submitted_at',
        'lock_version',
    ];

    protected $casts = [
        'section_declarations' => 'array',
        'pending_sections' => 'array',
        'status' => ApplicationStatus::class,
        'submitted_at' => 'datetime',
    ];

    public function distribuidora(): HasOne
    {
        return $this->hasOne(Distribuidora::class, 'application_id');
    }

    public function autorizacion(): HasOne
    {
        return $this->hasOne(ApplicationAuthorization::class, 'application_id');
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

    public function datosPersonales(): HasOne
    {
        return $this->hasOne(DatosPersonalesSolicitud::class, 'application_id');
    }

    public function domicilios(): HasMany
    {
        return $this->hasMany(DomicilioSolicitud::class, 'application_id');
    }

    public function domicilioActual(): HasOne
    {
        return $this->hasOne(DomicilioSolicitud::class, 'application_id')->where('is_current', true);
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(BranchRecord::class, 'branch_id');
    }

    public function transitionTo(ApplicationStatus $newStatus, string $userId, ?string $reason = null): void
    {
        $validTransitions = [
            ApplicationStatus::COORDINATOR_REVIEW->value => [ApplicationStatus::DRAFT, ApplicationStatus::VERIFIER_ASSIGNED],
            ApplicationStatus::VERIFIER_ASSIGNED->value => [ApplicationStatus::PHYSICAL_VERIFICATION],
            ApplicationStatus::PHYSICAL_VERIFICATION->value => [ApplicationStatus::COORDINATOR_CORRECTION, ApplicationStatus::COORDINATOR_EVALUATION, ApplicationStatus::TERMINATED_UNFAVORABLE],
            ApplicationStatus::COORDINATOR_CORRECTION->value => [ApplicationStatus::COORDINATOR_EVALUATION],
            ApplicationStatus::COORDINATOR_EVALUATION->value => [ApplicationStatus::MANAGER_AUTHORIZATION, ApplicationStatus::TERMINATED_UNFAVORABLE],
            ApplicationStatus::MANAGER_AUTHORIZATION->value => [ApplicationStatus::REJECTED, ApplicationStatus::AUTHORIZED_PENDING_ACTIVATION],
        ];

        $allowed = $validTransitions[$this->status->value] ?? [];
        if (! in_array($newStatus, $allowed, true)) {
            throw new BusinessException('INVALID_TRANSITION', "Transition from {$this->status->value} to {$newStatus->value} is not allowed.");
        }

        $oldStatus = $this->status;
        $this->status = $newStatus;
        $this->save();

        ApplicationStateTransition::create([
            'application_id' => $this->id,
            'from_status' => $oldStatus,
            'to_status' => $newStatus,
            'user_id' => $userId,
            'reason' => $reason,
        ]);
    }
}
