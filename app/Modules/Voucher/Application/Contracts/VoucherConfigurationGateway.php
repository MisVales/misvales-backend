<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Contracts;

use App\Modules\Voucher\Application\DTOs\CategoryConfiguration;
use App\Modules\Voucher\Application\DTOs\ProductConfiguration;
use Carbon\CarbonImmutable;

/** Frontera de M08 hacia las versiones publicadas e inmutables de M03. */
interface VoucherConfigurationGateway
{
    public function product(string $productId, CarbonImmutable $at): ProductConfiguration;

    public function category(string $categoryId, string $versionId, CarbonImmutable $at): CategoryConfiguration;
}
