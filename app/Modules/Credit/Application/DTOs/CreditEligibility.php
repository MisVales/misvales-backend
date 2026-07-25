<?php

declare(strict_types=1);

namespace App\Modules\Credit\Application\DTOs;

use App\Modules\Credit\Domain\ValueObjects\CreditRange;
use App\Modules\Credit\Domain\ValueObjects\Money;

final readonly class CreditEligibility
{
    public function __construct(
        public bool $eligible,
        public Money $availableBalance,
        public ?CreditRange $restrictionRange,
        public ?string $restrictionId,
    ) {}
}
