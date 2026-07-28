<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Commands\GenerateVoucher;

use App\Models\User;
use App\Modules\Voucher\Application\DTOs\OperationMetadata;

final readonly class Command
{
    public function __construct(
        public User $actor,
        public string $clientId,
        public string $productId,
        public OperationMetadata $metadata,
    ) {}
}
