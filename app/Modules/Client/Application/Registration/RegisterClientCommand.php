<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Registration;

use App\Modules\Client\Application\Security\ClientActorContext;

/** Comando completo e inmutable para el alta idempotente de un cliente. */
final readonly class RegisterClientCommand
{
    public function __construct(
        public string $givenNames,
        public string $surnames,
        public string $curp,
        public ?string $rfc,
        public ?string $birthDate,
        public ?string $birthPlace,
        public ?string $birthState,
        public ?string $birthCity,
        public AddressInput $address,
        public string $officialIdentificationMediaId,
        public string $addressProofMediaId,
        public string $bankAccount,
        public string $idempotencyKey,
        public string $requestId,
        public ClientActorContext $actor,
    ) {}
}
