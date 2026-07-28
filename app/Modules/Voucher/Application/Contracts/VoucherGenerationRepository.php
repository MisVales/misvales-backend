<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Contracts;

use App\Modules\Voucher\Application\DTOs\GeneratedVoucherData;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\VoucherModel;

interface VoucherGenerationRepository
{
    public function clientHasHistory(string $clientId): bool;

    public function create(GeneratedVoucherData $data): VoucherModel;
}
