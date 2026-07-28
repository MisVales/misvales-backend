<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\DTOs;

use App\Modules\Voucher\Domain\ValueObjects\Percentage;

/** Versión publicada de categoría asociada por M05 y resuelta en M03. */
final readonly class CategoryConfiguration
{
    public function __construct(
        public string $categoryId,
        public string $versionId,
        public int $version,
        public string $name,
        public Percentage $profitRate,
    ) {}
}
