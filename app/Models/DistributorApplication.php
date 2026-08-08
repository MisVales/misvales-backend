<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Exceptions\BusinessException;
use App\Models\Concerns\HasOptimisticLocking;
use App\Traits\MasksSensitiveData;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DistributorApplication extends Model
{
    use HasFactory;
    use HasOptimisticLocking, HasUuids, MasksSensitiveData;

    protected $table = 'distributor_applications_m5';

    protected $fillable = [
        'applicant_data',
        'pending_sections',
        'status',
        'branch_id',
        'coordinator_id',
        'verifier_id',
        'manager_id',
        'lock_version',
    ];

    protected $appends = ['masked_applicant_data'];

    protected $casts = [
        'applicant_data' => 'array',
        'masked_applicant_data' => 'array',
        'pending_sections' => 'array',
        'status' => ApplicationStatus::class,
    ];

    public function distribuidora(): HasOne
    {
        return $this->hasOne(Distribuidora::class, 'application_id');
    }

    public function autorizacion(): HasOne
    {
        return $this->hasOne(ApplicationAuthorization::class, 'application_id');
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
