<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Application\DTOs;

use Carbon\CarbonImmutable;

final readonly class VoucherIssuanceDecision
{
    public function __construct(
        public bool $allowed,
        public ?string $restrictionCode,
        public int $profileVersion,
        public ?CarbonImmutable $appliedAt,
    ) {}
}
