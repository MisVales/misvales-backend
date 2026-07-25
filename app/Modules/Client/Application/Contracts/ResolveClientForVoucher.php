<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

use App\Modules\Client\Application\Security\ClientActorContext;

/** Contrato interno de identidad y asociación para M08; no decide crédito. */
interface ResolveClientForVoucher
{
    public function handle(string $clientId, ClientActorContext $actor): ClientVoucherSelection;
}
