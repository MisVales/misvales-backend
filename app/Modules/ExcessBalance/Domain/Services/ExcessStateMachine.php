<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Domain\Services;

use App\Modules\ExcessBalance\Domain\Exceptions\ExcessBalanceException;
use App\Modules\Payment\Domain\Enums\ExcessBalanceStatus;

final class ExcessStateMachine
{
    public function assertTransition(ExcessBalanceStatus $from, ExcessBalanceStatus $to): void
    {
        $allowed = match ($from) {
            ExcessBalanceStatus::PENDING_DECISION => [
                ExcessBalanceStatus::CREDIT_BALANCE,
                ExcessBalanceStatus::REFUND_PENDING,
            ],
            ExcessBalanceStatus::CREDIT_BALANCE => [
                ExcessBalanceStatus::PARTIALLY_APPLIED,
                ExcessBalanceStatus::FULLY_APPLIED,
            ],
            ExcessBalanceStatus::PARTIALLY_APPLIED => [
                ExcessBalanceStatus::PARTIALLY_APPLIED,
                ExcessBalanceStatus::FULLY_APPLIED,
            ],
            default => [],
        };

        if (! in_array($to, $allowed, true)) {
            throw ExcessBalanceException::stateConflict();
        }
    }
}
