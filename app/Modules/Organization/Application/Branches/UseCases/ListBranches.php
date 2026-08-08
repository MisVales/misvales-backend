<?php

namespace App\Modules\Organization\Application\Branches\UseCases;

use App\Modules\Organization\Application\Branches\Queries\BranchListCriteria;
use App\Modules\Organization\Application\Branches\Queries\PaginatedBranches;
use App\Modules\Organization\Application\Branches\Repositories\BranchReadRepository;
use App\Modules\Organization\Domain\Assignments\Services\OrganizationScopeResolver;

final readonly class ListBranches
{
    public function __construct(
        private BranchReadRepository $branches,
        private OrganizationScopeResolver $scopeResolver,
    ) {}

    public function handle(string $actorId, BranchListCriteria $criteria): PaginatedBranches
    {
        return $this->branches->paginate(
            criteria: $criteria,
            scope: $this->scopeResolver->resolve($actorId),
        );
    }
}
