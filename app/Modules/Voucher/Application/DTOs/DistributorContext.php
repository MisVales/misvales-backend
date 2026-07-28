<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\DTOs;

/** Contexto operativo vigente de la distribuidora autenticada. */
final readonly class DistributorContext
{
    public function __construct(
        public string $id,
        public int $userId,
        public string $userPublicId,
        public int $branchId,
        public string $branchPublicId,
        public string $categoryId,
        public string $categoryVersionId,
    ) {}
}
