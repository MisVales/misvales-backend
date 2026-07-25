<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Contracts;

use App\Modules\DistributorOnboarding\Application\Security\ActorContext;

/** Puerto de M02 para contexto, validación y asignación organizacional. */
interface OrganizationPort
{
    public function resolveCreationContext(ActorContext $actor): ResponsibleContext;

    public function assertResponsibleCoordinator(int $coordinatorUserId, int $branchId): void;

    public function assertVerifier(int $verifierUserId, int $branchId): void;

    public function createDistributorAssignment(
        string $distributorId,
        int $coordinatorUserId,
        int $branchId,
        string $operationKey,
    ): string;
}
