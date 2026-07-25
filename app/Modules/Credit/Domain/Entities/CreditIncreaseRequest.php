<?php

declare(strict_types=1);

namespace App\Modules\Credit\Domain\Entities;

use App\Modules\Credit\Domain\Enums\IncreaseRequestStatus;
use App\Modules\Credit\Domain\ValueObjects\Money;

final readonly class CreditIncreaseRequest
{
    public function __construct(
        public string $id,
        public string $folio,
        public int $distributorId,
        public Money $requestedAmount,
        public ?Money $recommendedAmount,
        public ?Money $authorizedAmount,
        public IncreaseRequestStatus $status,
        public int $lockVersion,
    ) {}
}
