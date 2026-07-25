<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Portfolio;

use App\Modules\Client\Application\Security\ClientActorContext;
use App\Modules\Client\Domain\Portfolio\PortfolioStatus;

/** Corrige exclusivamente estado y nota conservando el valor anterior. */
final readonly class UpdatePortfolioEntryCommand
{
    public function __construct(
        public string $clientId,
        public string $entryId,
        public ?PortfolioStatus $status,
        public ?string $note,
        public int $expectedVersion,
        public string $requestId,
        public ClientActorContext $actor,
    ) {}
}
