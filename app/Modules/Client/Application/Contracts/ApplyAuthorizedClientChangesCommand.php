<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

use App\Modules\Client\Application\Security\ClientActorContext;

/** Comando acotado a los campos exactos autorizados por M09. */
final readonly class ApplyAuthorizedClientChangesCommand
{
    /**
     * @param  list<string>  $authorizedFields
     * @param  array<string, mixed>  $newValues
     */
    public function __construct(
        public string $authorizationId,
        public string $clientId,
        public array $authorizedFields,
        public array $newValues,
        public string $reason,
        public string $operationId,
        public int $expectedClientVersion,
        public string $requestId,
        public ClientActorContext $cashier,
    ) {}
}
