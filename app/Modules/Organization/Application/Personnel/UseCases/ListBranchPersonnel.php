<?php

namespace App\Modules\Organization\Application\Personnel\UseCases;

use App\Modules\Organization\Application\Events\OrganizationEventPublisher;
use App\Modules\Organization\Application\Personnel\Queries\PaginatedPersonnel;
use App\Modules\Organization\Application\Personnel\Queries\PersonnelListCriteria;
use App\Modules\Organization\Application\Personnel\Repositories\PersonnelReadRepository;
use App\Modules\Organization\Domain\Assignments\Exceptions\OrganizationScopeDenied;
use App\Modules\Organization\Domain\Assignments\Services\OrganizationScopeResolver;
use App\Modules\Organization\Domain\Branches\Exceptions\BranchNotFound;
use App\Modules\Organization\Domain\Branches\Repositories\BranchRepository;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;
use App\Modules\Organization\Domain\Events\OrganizationEvent;
use App\Modules\Organization\Domain\Events\OrganizationEventType;
use Illuminate\Support\Str;

final readonly class ListBranchPersonnel
{
    public function __construct(
        private BranchRepository $branches,
        private PersonnelReadRepository $personnel,
        private OrganizationScopeResolver $scopeResolver,
        private OrganizationEventPublisher $events,
    ) {}

    public function handle(
        string $branchId,
        string $actorId,
        PersonnelListCriteria $criteria,
    ): PaginatedPersonnel {
        $branch = $this->branches->find(BranchId::fromString($branchId))
            ?? throw new BranchNotFound($branchId);
        $scope = $this->scopeResolver->resolve($actorId);

        if (! $scope->allows($branch->id()->value())) {
            $this->events->publish(new OrganizationEvent(
                id: Str::uuid()->toString(),
                type: OrganizationEventType::ORGANIZATION_SCOPE_DENIED,
                aggregateType: 'branch',
                aggregateId: $branch->id()->value(),
                actorId: $actorId,
                branchId: $branch->id()->value(),
                reason: 'OUT_OF_SCOPE',
                details: ['operation' => 'LIST_BRANCH_PERSONNEL'],
                outcome: 'DENIED',
            ));
            throw new OrganizationScopeDenied;
        }

        return $this->personnel->paginateForBranch($branch->id()->value(), $criteria, $scope);
    }
}
