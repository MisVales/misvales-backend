<?php

declare(strict_types=1);

namespace App\Modules\Credit\Domain\Entities;

use App\Modules\Credit\Domain\Enums\RestrictionStatus;
use App\Modules\Credit\Domain\Enums\RestrictionTriggerType;
use App\Modules\Credit\Domain\ValueObjects\Money;

final readonly class CreditRestriction
{
    public function __construct(
        public string $id,
        public RestrictionTriggerType $triggerType,
        public string $triggerId,
        public Money $baseTotalAuthorized,
        public Money $tolerance,
        public RestrictionStatus $status,
        public ?string $boundVoucherId = null,
    ) {}
}
