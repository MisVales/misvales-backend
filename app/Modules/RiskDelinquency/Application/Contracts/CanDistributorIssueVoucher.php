<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Application\Contracts;

use App\Modules\RiskDelinquency\Application\DTOs\VoucherIssuanceDecision;

/** Contrato sin efectos para la revalidación transaccional de M08 y M09. */
interface CanDistributorIssueVoucher
{
    public function check(int $distributorId): VoucherIssuanceDecision;

    public function assertAllowed(int $distributorId): void;
}
