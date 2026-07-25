<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Client\Application\Contracts\ConfirmedVoucherPort;

final class FakeConfirmedClientVoucher implements ConfirmedVoucherPort
{
    public int $assertions = 0;

    public function assertConfirmedForClient(string $voucherId, string $clientId, string $distributorId, string $amount): void
    {
        $this->assertions++;
    }
}
