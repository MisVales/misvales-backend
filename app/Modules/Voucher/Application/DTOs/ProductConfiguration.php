<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\DTOs;

use App\Modules\Voucher\Domain\ValueObjects\Money;
use App\Modules\Voucher\Domain\ValueObjects\Percentage;

/** Versión publicada de producto resuelta por M03. */
final readonly class ProductConfiguration
{
    public function __construct(
        public string $productId,
        public string $versionId,
        public int $version,
        public string $name,
        public Money $capital,
        public Percentage $commissionRate,
        public Percentage $interestRate,
        public Money $insurance,
        public int $fortnights,
    ) {}
}
