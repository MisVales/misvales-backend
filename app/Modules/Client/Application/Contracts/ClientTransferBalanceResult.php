<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

/** Resultado informativo que M15 debe volver a validar al aplicar el cambio. */
final readonly class ClientTransferBalanceResult
{
    public function __construct(
        public string $clientId,
        public string $distributorId,
        public string $totalBalance,
        public ?string $overdueBalance,
        public bool $trackingEnabled,
        public ?string $confirmedAt,
        public ?int $confirmedBy,
        public int $portfolioVersion,
        public bool $allowed,
    ) {}
}
