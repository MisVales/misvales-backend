<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Registration;

use App\Modules\Client\Application\Contracts\DistributorProfile;
use App\Modules\Client\Persistence\Models\Client;

/** Resultado reducido del alta, sin afirmar creación de prevale o crédito. */
final readonly class ClientRegistrationResult
{
    public function __construct(
        public Client $client,
        public DistributorProfile $distributor,
        public bool $replayed,
    ) {}
}
