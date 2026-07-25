<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

/** Datos que M09 debe validar y reservar transaccionalmente. */
final readonly class AuthorizedClientChange
{
    /**
     * @param  list<string>  $fields
     */
    public function __construct(
        public string $authorizationId,
        public string $clientId,
        public array $fields,
        public int $cashierUserId,
        public int $branchId,
        public string $operationId,
    ) {}
}
