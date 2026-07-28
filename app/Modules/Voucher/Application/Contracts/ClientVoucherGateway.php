<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Contracts;

use App\Models\User;
use App\Modules\Voucher\Application\DTOs\ClientContext;

/** Adaptador M08 sobre la selección pública de cliente de M06. */
interface ClientVoucherGateway
{
    public function lockAssigned(string $clientId, User $actor): ClientContext;
}
