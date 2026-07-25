<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Portfolio;

use App\Modules\Client\Application\Security\ClientActorContext;

/** Confirmación explícita para M15 cuando la cartera opcional no fue utilizada. */
final readonly class ConfirmZeroPortfolioCommand
{
    public function __construct(
        public string $clientId,
        public int $expectedPortfolioVersion,
        public string $operationId,
        public string $requestId,
        public ClientActorContext $actor,
    ) {}
}
