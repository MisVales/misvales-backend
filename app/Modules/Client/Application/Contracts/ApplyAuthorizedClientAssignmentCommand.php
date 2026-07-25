<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

use App\Modules\Client\Application\Security\ClientActorContext;

/** Datos inmutables de una reasignación ya autorizada por M15. */
final readonly class ApplyAuthorizedClientAssignmentCommand
{
    public function __construct(
        public string $mobilityOperationId,
        public string $clientId,
        public string $sourceDistributorId,
        public string $destinationDistributorId,
        public string $effectiveAt,
        public string $reason,
        public int $expectedClientVersion,
        public int $expectedPortfolioVersion,
        public string $requestId,
        public ClientActorContext $executor,
    ) {}
}
