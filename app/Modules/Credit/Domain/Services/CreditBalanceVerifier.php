<?php

declare(strict_types=1);

namespace App\Modules\Credit\Domain\Services;

use App\Modules\Credit\Application\Services\CreditLedgerReconstructor;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditLineModel;

final readonly class CreditBalanceVerifier
{
    public function __construct(private CreditLedgerReconstructor $reconstructor) {}

    public function verify(CreditLineModel $line): void
    {
        $this->reconstructor->assertMatches($line);
    }
}
