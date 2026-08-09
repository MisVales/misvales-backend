<?php

namespace App\Modules\Organization\Application\Branches\Repositories;

use App\Modules\Organization\Application\Branches\Queries\BranchListCriteria;
use App\Modules\Organization\Application\Branches\Queries\PaginatedBranches;
use App\Modules\Organization\Domain\Assignments\EffectiveOrganizationScope;

interface BranchReadRepository
{
    public function paginate(
        BranchListCriteria $criteria,
        EffectiveOrganizationScope $scope,
    ): PaginatedBranches;
}
