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

final readonly class ListBranchPersonnel
{
    public function __construct(
        private BranchRepository $branches,
        private PersonnelReadRepository $personnel,
        private OrganizationScopeResolver $scopeResolver,
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
            throw new OrganizationScopeDenied;
        }

        return $this->personnel->paginateForBranch($branch->id()->value(), $criteria, $scope);
    }
}
