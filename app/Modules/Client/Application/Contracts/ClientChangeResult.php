<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

/** Resultado idempotente de una modificación sensible autorizada. */
final readonly class ClientChangeResult
{
    /** @param list<string> $changedFields */
    public function __construct(
        public string $clientId,
        public int $version,
        public array $changedFields,
        public bool $replayed,
    ) {}
}
