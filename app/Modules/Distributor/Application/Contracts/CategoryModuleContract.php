<?php

namespace App\Modules\Distributor\Application\Contracts;

interface CategoryModuleContract
{
    /**
     * @throws \App\Modules\Distributor\Domain\Distributors\DistributorDomainException if not assignable
     */
    public function getAssignableCategoryVersion(string $categoryVersionId): CategoryVersionInfo;
}
