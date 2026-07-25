<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Contracts;

/** Identidad de la cuenta DISTRIBUTOR aprovisionada por M01. */
final readonly class AccountProvisionResult
{
    public function __construct(public string $accountId) {}
}
