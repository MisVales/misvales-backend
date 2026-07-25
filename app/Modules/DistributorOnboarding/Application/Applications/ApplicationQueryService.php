<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Applications;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\DistributorOnboarding\Application\Security\ActorContext;
use App\Modules\DistributorOnboarding\Application\Support\OnboardingAuthorizer;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;
use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** Consultas paginadas cuyo alcance se aplica en SQL antes de filtros y conteos. */
final readonly class ApplicationQueryService
{
    public function __construct(private OnboardingAuthorizer $authorizer) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, DistributorApplication>
     */
    public function paginate(ActorContext $actor, array $filters): LengthAwarePaginator
    {
        $query = $this->scopedQuery($actor)
            ->select([
                'id', 'public_id', 'folio', 'branch_id', 'coordinator_user_id',
                'status', 'result', 'lock_version', 'created_at', 'updated_at',
            ])
            ->with([
                'branch:id,public_id,name',
                'coordinator:id,public_id,name',
                'activeVerifierAssignment:id,public_id,application_id,verifier_user_id',
                'activeVerifierAssignment.verifier:id,public_id,name',
            ]);

        $query
            ->when($filters['folio'] ?? null, fn (Builder $builder, mixed $value): Builder => $builder->where('folio', (string) $value))
            ->when($filters['status'] ?? null, fn (Builder $builder, mixed $value): Builder => $builder->where('status', (string) $value))
            ->when($filters['result'] ?? null, fn (Builder $builder, mixed $value): Builder => $builder->where('result', (string) $value))
            ->when($filters['branch_id'] ?? null, fn (Builder $builder, mixed $value): Builder => $builder->whereHas('branch', fn (Builder $branch): Builder => $branch->where('public_id', (string) $value)))
            ->when($filters['coordinator_id'] ?? null, fn (Builder $builder, mixed $value): Builder => $builder->whereHas('coordinator', fn (Builder $coordinator): Builder => $coordinator->where('public_id', (string) $value)))
            ->when($filters['verifier_id'] ?? null, fn (Builder $builder, mixed $value): Builder => $builder->whereHas('activeVerifierAssignment.verifier', fn (Builder $verifier): Builder => $verifier->where('public_id', (string) $value)))
            ->when($filters['from'] ?? null, fn (Builder $builder, mixed $value): Builder => $builder->whereDate('created_at', '>=', (string) $value))
            ->when($filters['to'] ?? null, fn (Builder $builder, mixed $value): Builder => $builder->whereDate('created_at', '<=', (string) $value));

        $sort = (string) ($filters['sort'] ?? 'created_at');
        $direction = (string) ($filters['direction'] ?? 'desc');

        return $query
            ->orderBy($sort, $direction)
            ->orderBy('id', $direction)
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    public function find(ActorContext $actor, string $publicId): DistributorApplication
    {
        $application = $this->scopedQuery($actor)
            ->where('public_id', $publicId)
            ->with([
                'branch:id,public_id,name',
                'coordinator:id,public_id,name',
                'personalData',
                'familyMembers',
                'familyReferences',
                'residences',
                'vehicles',
                'assetsLiabilities',
                'employments',
                'laborReferences',
                'commercialCredits',
                'activeVerifierAssignment.verifier:id,public_id,name',
                'visit',
                'differences',
                'corrections.difference:id,public_id',
                'corrections.corrector:id,public_id,name',
                'evaluation',
                'authorization',
                'activation',
            ])
            ->first();

        if ($application === null) {
            throw OnboardingDomainException::scopeDenied();
        }

        $this->authorizer->assertCanView($actor, $application);

        return $application;
    }

    /** @return Builder<DistributorApplication> */
    private function scopedQuery(ActorContext $actor): Builder
    {
        $query = DistributorApplication::query();

        if ($actor->hasPermission(PermissionCode::ONBOARDING_APPLICATIONS_VIEW_GLOBAL->value)) {
            return $query;
        }
        if (
            $actor->hasPermission(PermissionCode::ONBOARDING_APPLICATIONS_VIEW_BRANCH->value)
            && $actor->branchId !== null
        ) {
            return $query->forBranch($actor->branchId);
        }
        if (
            $actor->role === RoleCode::COORDINATOR
            && $actor->hasPermission(PermissionCode::ONBOARDING_APPLICATIONS_VIEW_ASSIGNED->value)
        ) {
            return $query->forCoordinator($actor->userId);
        }
        if (
            $actor->role === RoleCode::VERIFIER
            && $actor->hasPermission(PermissionCode::ONBOARDING_APPLICATIONS_VIEW_ASSIGNED->value)
        ) {
            return $query->forVerifier($actor->userId);
        }

        throw OnboardingDomainException::authorizationDenied();
    }
}
