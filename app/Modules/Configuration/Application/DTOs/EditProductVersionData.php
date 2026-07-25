<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\DTOs;

/**
 * DTO para editar un borrador de versión de producto.
 */
final readonly class EditProductVersionData
{
    public function __construct(
        public string $versionPublicId,
        public string $amount,
        public string $loanCommissionRate,
        public string $interestRatePerFortnight,
        public string $insuranceAmount,
        public int $fortnightCount,
        public int $lockVersion,
        public int $actorUserId,
    ) {}
}
