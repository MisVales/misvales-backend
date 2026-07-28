<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Contracts;

use App\Models\User;
use App\Modules\Voucher\Application\DTOs\DistributorContext;

/** Frontera de M08 hacia el perfil y categoría vigentes de M05. */
interface DistributorVoucherGateway
{
    public function lockAuthenticated(User $actor): DistributorContext;
}
