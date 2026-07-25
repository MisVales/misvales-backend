<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

/** PII mínima entregable solo después de validar vale, cajera y sucursal. */
final readonly class ClientCashierVerificationData
{
    /**
     * @param  array<string, string|null>  $address
     * @param  list<array{type:string,private_reference:string}>  $documents
     */
    public function __construct(
        public string $clientId,
        public string $displayName,
        public array $address,
        public string $bankAccount,
        public array $documents,
    ) {}
}
