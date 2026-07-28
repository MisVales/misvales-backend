<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\DTOs;

use App\Modules\Credit\Application\DTOs\CreditEligibility;
use App\Modules\Voucher\Domain\DTOs\VoucherCalculation;
use App\Modules\Voucher\Domain\Enums\VoucherType;

/** Todos los datos derivados por backend necesarios para persistir un vale. */
final readonly class GeneratedVoucherData
{
    public function __construct(
        public string $id,
        public string $folio,
        public VoucherType $type,
        public DistributorContext $distributor,
        public ClientContext $client,
        public ProductConfiguration $product,
        public CategoryConfiguration $category,
        public CreditEligibility $credit,
        public int $generatedBy,
        public VoucherCalculation $calculation,
    ) {}
}
