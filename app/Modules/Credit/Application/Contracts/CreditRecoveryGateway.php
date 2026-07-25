<?php

declare(strict_types=1);

namespace App\Modules\Credit\Application\Contracts;

use App\Modules\Credit\Application\DTOs\CapitalRecovery;
use App\Modules\Credit\Domain\ValueObjects\Money;

interface CreditRecoveryGateway
{
    public function recover(CapitalRecovery $recovery): Money;
}
