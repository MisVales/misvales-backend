<?php

namespace App\Modules\Distributor\Application\Contracts;

class ProvisionAuthorizedDistributorCommand
{
    public function __construct(
        public readonly string $operationId,
        public readonly string $onboardingApplicationId,
        public readonly string $userId,
        public readonly string $branchId,
        public readonly ?string $activatedBy,
        public readonly \DateTimeImmutable $activatedAt
    ) {
    }
}
