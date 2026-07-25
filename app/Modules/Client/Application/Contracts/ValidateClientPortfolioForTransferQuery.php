<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

/** Consulta versionada del saldo informativo de una asociación vigente. */
final readonly class ValidateClientPortfolioForTransferQuery
{
    public function __construct(
        public string $clientId,
        public string $sourceDistributorId,
        public int $expectedPortfolioVersion,
    ) {}
}
