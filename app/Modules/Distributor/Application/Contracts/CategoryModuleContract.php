<?php

namespace App\Modules\Distributor\Application\Contracts;

use App\Modules\Distributor\Domain\Distributors\DistributorDomainException;

interface CategoryModuleContract
{
    /**
     * @throws DistributorDomainException if not assignable
     */
    public function getAssignableCategoryVersion(string $categoryVersionId): CategoryVersionInfo;
}
