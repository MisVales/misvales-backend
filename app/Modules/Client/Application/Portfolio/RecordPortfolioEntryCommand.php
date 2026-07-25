<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Portfolio;

use App\Modules\Client\Application\Security\ClientActorContext;
use App\Modules\Client\Domain\Portfolio\PortfolioEntryType;
use App\Modules\Client\Domain\Portfolio\PortfolioStatus;

/** Comando idempotente para un movimiento informativo de la distribuidora. */
final readonly class RecordPortfolioEntryCommand
{
    public function __construct(
        public string $clientId,
        public PortfolioEntryType $type,
        public ?string $amount,
        public PortfolioStatus $status,
        public string $occurredOn,
        public ?string $note,
        public string $idempotencyKey,
        public string $requestId,
        public ClientActorContext $actor,
    ) {}
}
