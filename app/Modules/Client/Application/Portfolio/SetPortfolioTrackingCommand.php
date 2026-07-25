<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Portfolio;

use App\Modules\Client\Application\Security\ClientActorContext;

/** Preferencia explícita de la distribuidora para su asociación vigente. */
final readonly class SetPortfolioTrackingCommand
{
    public function __construct(
        public string $clientId,
        public bool $enabled,
        public int $expectedVersion,
        public string $requestId,
        public ClientActorContext $actor,
    ) {}
}
