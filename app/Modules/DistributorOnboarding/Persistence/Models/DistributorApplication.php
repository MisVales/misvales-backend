<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Models;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationStatus;
use App\Modules\DistributorOnboarding\Persistence\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Registro raíz y control de concurrencia de una solicitud M04.
 *
 * @property int $id
 * @property string $public_id
 * @property string $folio
 * @property string $contact_email
 * @property string $normalized_email_hash
 * @property string $account_name
 * @property int $branch_id
 * @property int $coordinator_user_id
 * @property ApplicationStatus $status
 * @property ApplicationStatus|null $result
 * @property int $lock_version
 */
final class DistributorApplication extends Model
{
    use HasPublicId;

    /** @var list<string> */
    protected $guarded = [
        'id', 'public_id', 'folio', 'branch_id', 'coordinator_user_id', 'status',
        'result', 'lock_version', 'created_by', 'submitted_by', 'submitted_at',
    ];

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_user_id');
    }

    /** @return HasOne<ApplicationPersonalData, $this> */
    public function personalData(): HasOne
    {
        return $this->hasOne(ApplicationPersonalData::class, 'application_id');
    }

    /** @return HasMany<ApplicationFamilyMember, $this> */
    public function familyMembers(): HasMany
    {
        return $this->hasMany(ApplicationFamilyMember::class, 'application_id')->whereNull('retired_at');
    }

    /** @return HasMany<ApplicationFamilyReference, $this> */
    public function familyReferences(): HasMany
    {
        return $this->hasMany(ApplicationFamilyReference::class, 'application_id')->whereNull('retired_at');
    }

    /** @return HasMany<ApplicationResidence, $this> */
    public function residences(): HasMany
    {
        return $this->hasMany(ApplicationResidence::class, 'application_id')->whereNull('retired_at');
    }

    /** @return HasMany<ApplicationVehicle, $this> */
    public function vehicles(): HasMany
    {
        return $this->hasMany(ApplicationVehicle::class, 'application_id')->whereNull('retired_at');
    }

    /** @return HasMany<ApplicationAssetLiability, $this> */
    public function assetsLiabilities(): HasMany
    {
        return $this->hasMany(ApplicationAssetLiability::class, 'application_id')->whereNull('retired_at');
    }

    /** @return HasMany<ApplicationEmployment, $this> */
    public function employments(): HasMany
    {
        return $this->hasMany(ApplicationEmployment::class, 'application_id')->whereNull('retired_at');
    }

    /** @return HasMany<ApplicationLaborReference, $this> */
    public function laborReferences(): HasMany
    {
        return $this->hasMany(ApplicationLaborReference::class, 'application_id')->whereNull('retired_at');
    }

    /** @return HasMany<ApplicationCommercialCredit, $this> */
    public function commercialCredits(): HasMany
    {
        return $this->hasMany(ApplicationCommercialCredit::class, 'application_id')->whereNull('retired_at');
    }

    /** @return HasMany<VerifierAssignment, $this> */
    public function verifierAssignments(): HasMany
    {
        return $this->hasMany(VerifierAssignment::class, 'application_id');
    }

    /** @return HasOne<VerifierAssignment, $this> */
    public function activeVerifierAssignment(): HasOne
    {
        return $this->hasOne(VerifierAssignment::class, 'application_id')->where('active_slot', true);
    }

    /** @return HasOne<VerificationVisit, $this> */
    public function visit(): HasOne
    {
        return $this->hasOne(VerificationVisit::class, 'application_id');
    }

    /** @return HasMany<VerificationDifference, $this> */
    public function differences(): HasMany
    {
        return $this->hasMany(VerificationDifference::class, 'application_id');
    }

    /** @return HasMany<ApplicationCorrection, $this> */
    public function corrections(): HasMany
    {
        return $this->hasMany(ApplicationCorrection::class, 'application_id');
    }

    /** @return HasOne<ApplicationEvaluation, $this> */
    public function evaluation(): HasOne
    {
        return $this->hasOne(ApplicationEvaluation::class, 'application_id');
    }

    /** @return HasOne<ApplicationAuthorization, $this> */
    public function authorization(): HasOne
    {
        return $this->hasOne(ApplicationAuthorization::class, 'application_id');
    }

    /** @return HasOne<ApplicationActivationRecord, $this> */
    public function activation(): HasOne
    {
        return $this->hasOne(ApplicationActivationRecord::class, 'application_id');
    }

    /** @return HasMany<ApplicationStatusHistory, $this> */
    public function histories(): HasMany
    {
        return $this->hasMany(ApplicationStatusHistory::class, 'application_id');
    }

    /** @return HasMany<ApplicationAudit, $this> */
    public function audits(): HasMany
    {
        return $this->hasMany(ApplicationAudit::class, 'application_id');
    }

    /** @param Builder<self> $query */
    public function scopeForBranch(Builder $query, int $branchId): void
    {
        $query->where('branch_id', $branchId);
    }

    /** @param Builder<self> $query */
    public function scopeForCoordinator(Builder $query, int $userId): void
    {
        $query->where('coordinator_user_id', $userId);
    }

    /** @param Builder<self> $query */
    public function scopeForVerifier(Builder $query, int $userId): void
    {
        $query->whereHas('activeVerifierAssignment', fn (Builder $assignment): Builder => $assignment->where('verifier_user_id', $userId));
    }

    protected function casts(): array
    {
        return [
            'contact_email' => 'encrypted',
            'account_name' => 'encrypted',
            'status' => ApplicationStatus::class,
            'result' => ApplicationStatus::class,
            'lock_version' => 'integer',
            'submitted_at' => 'immutable_datetime',
        ];
    }
}
