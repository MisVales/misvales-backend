<?php

namespace App\Modules\Access\Domain\Accounts;

use Carbon\CarbonImmutable;

/** Describe una autorización final de distribuidora recibida desde el módulo propietario. */
final readonly class DistributorFinalAuthorizationCompleted
{
    public function __construct(
        public string $requestId,
        public string $distributorId,
        public string $email,
        public string $name,
        public int $branchId,
        public int $coordinatorUserId,
        public int $authorizedBy,
        public string $initialCreditLine,
        public CarbonImmutable $authorizedAt,
        public string $eventKey,
        public bool $isFinal = true,
    ) {}
}
