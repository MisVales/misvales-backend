<?php

declare(strict_types=1);

namespace App\Modules\Credit\Infrastructure\Persistence\Eloquent\Mappers;

use App\Modules\Credit\Domain\Aggregates\CreditLine;
use App\Modules\Credit\Domain\ValueObjects\Money;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditLineModel;

final class CreditLineMapper
{
    public function toDomain(CreditLineModel $model): CreditLine
    {
        return new CreditLine(
            new Money($model->total_authorized),
            new Money($model->used_balance),
            new Money($model->recovered_capital_total),
        );
    }

    public function apply(CreditLine $line, CreditLineModel $model): void
    {
        $model->forceFill([
            'total_authorized' => $line->totalAuthorized->databaseValue(),
            'used_balance' => $line->usedBalance->databaseValue(),
            'available_balance' => $line->availableBalance()->databaseValue(),
            'recovered_capital_total' => $line->recoveredCapitalTotal->databaseValue(),
        ]);
    }
}
