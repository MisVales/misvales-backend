<?php

namespace App\Modules\Organization\Application\Personnel\UseCases;

use App\Modules\Organization\Application\Personnel\Queries\PaginatedPersonnel;
use App\Modules\Organization\Application\Personnel\Queries\PersonnelListCriteria;
use App\Modules\Organization\Application\Personnel\Repositories\PersonnelReadRepository;
use App\Modules\Organization\Domain\Assignments\Exceptions\OrganizationScopeDenied;
use App\Modules\Organization\Domain\Assignments\Services\OrganizationScopeResolver;
use App\Modules\Organization\Domain\Branches\Exceptions\BranchNotFound;
use App\Modules\Organization\Domain\Branches\Repositories\BranchRepository;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;

final readonly class ListPersonnel
{
    public function __construct(
        private PersonnelReadRepository $personnel,
        private OrganizationScopeResolver $scopeResolver,
        private BranchRepository $branches,
    ) {}

    public function handle(string $actorId, PersonnelListCriteria $criteria): PaginatedPersonnel
    {
        $scope = $this->scopeResolver->resolve($actorId);

        if ($criteria->branchId !== null) {
            $branch = $this->branches->find(BranchId::fromString($criteria->branchId))
                ?? throw new BranchNotFound($criteria->branchId);

            if (! $scope->allows($branch->id()->value())) {
                throw new OrganizationScopeDenied;
            }
        }

        return $this->personnel->paginate(
            criteria: $criteria,
            scope: $scope,
        );
    }
}
