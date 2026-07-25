<?php

declare(strict_types=1);

namespace App\Modules\Credit\Application\Services;

use App\Modules\Credit\Domain\Enums\RestrictionStatus;
use App\Modules\Credit\Domain\Enums\RestrictionTriggerType;
use App\Modules\Credit\Domain\Rules\FiftyPercentRule;
use App\Modules\Credit\Domain\ValueObjects\CreditRange;
use App\Modules\Credit\Domain\ValueObjects\Money;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditLineModel;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditUsageRestrictionModel;

final readonly class CreditRestrictionService
{
    public function __construct(private FiftyPercentRule $rule) {}

    public function create(
        CreditLineModel $line,
        RestrictionTriggerType $trigger,
        string $triggerId,
    ): CreditUsageRestrictionModel {
        $tolerance = new Money((string) config('credit.fifty_percent_tolerance'));
        $percentage = (string) config('credit.percentage');
        $range = $this->rule->range(
            new Money($line->total_authorized),
            new Money($line->available_balance),
            $tolerance,
            $percentage,
        );

        return CreditUsageRestrictionModel::query()->create([
            'credit_line_id' => $line->id,
            'trigger_type' => $trigger,
            'trigger_id' => $triggerId,
            'base_total_authorized' => $line->total_authorized,
            'percentage' => $percentage,
            'tolerance_amount' => $tolerance->databaseValue(),
            'reference_amount' => $range->reference->databaseValue(),
            'status' => RestrictionStatus::ACTIVE,
        ]);
    }

    public function activeForLine(int $lineId, bool $lock = false): ?CreditUsageRestrictionModel
    {
        $query = CreditUsageRestrictionModel::query()
            ->where('credit_line_id', $lineId)
            ->whereIn('status', [RestrictionStatus::ACTIVE->value, RestrictionStatus::BOUND->value])
            ->oldest('id');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function range(CreditUsageRestrictionModel $restriction, Money $available): CreditRange
    {
        return $this->rule->range(
            new Money($restriction->base_total_authorized),
            $available,
            new Money($restriction->tolerance_amount),
            $restriction->percentage,
        );
    }

    public function assertCapital(CreditUsageRestrictionModel $restriction, Money $available, Money $capital): CreditRange
    {
        $range = $this->range($restriction, $available);
        $this->rule->assertAdmissible($range, $capital);

        return $range;
    }
}
