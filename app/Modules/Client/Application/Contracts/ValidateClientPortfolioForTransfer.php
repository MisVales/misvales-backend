<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

/** Contrato de lectura utilizado exclusivamente por M15 antes de transferir. */
interface ValidateClientPortfolioForTransfer
{
    public function handle(ValidateClientPortfolioForTransferQuery $query): ClientTransferBalanceResult;
}
