<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Application\Contracts;

use App\Models\User;

/** Frontera M07 para identidad operativa, sucursal y coordinación vigentes. */
interface DistributorStatusPort
{
    public function lock(int $distributorId): User;
}
