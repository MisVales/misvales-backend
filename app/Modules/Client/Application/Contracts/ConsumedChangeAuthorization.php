<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

/** Identidades auditables confirmadas por M09 al consumir la autorización. */
final readonly class ConsumedChangeAuthorization
{
    public function __construct(
        public int $requestedBy,
        public int $authorizedBy,
    ) {}
}
