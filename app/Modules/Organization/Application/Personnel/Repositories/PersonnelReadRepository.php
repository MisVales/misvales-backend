<?php

namespace App\Modules\Organization\Application\Personnel\Repositories;

use App\Modules\Organization\Application\Personnel\Queries\PaginatedPersonnel;
use App\Modules\Organization\Application\Personnel\Queries\PersonnelListCriteria;
use App\Modules\Organization\Domain\Assignments\EffectiveOrganizationScope;

interface PersonnelReadRepository
{
    public function paginate(
        PersonnelListCriteria $criteria,
        EffectiveOrganizationScope $scope,
    ): PaginatedPersonnel;

    public function paginateForBranch(
        string $branchId,
        PersonnelListCriteria $criteria,
        EffectiveOrganizationScope $scope,
    ): PaginatedPersonnel;
}
