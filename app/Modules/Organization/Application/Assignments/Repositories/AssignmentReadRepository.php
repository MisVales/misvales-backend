<?php

namespace App\Modules\Organization\Application\Assignments\Repositories;

use App\Modules\Organization\Domain\Assignments\EffectiveOrganizationScope;

interface AssignmentReadRepository
{
    /** @return list<array<string, mixed>> */
    public function forUser(
        string $userId,
        bool $includeHistory,
        ?EffectiveOrganizationScope $scope = null,
    ): array;
}
