<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Client\Application\Contracts\AuthorizedMobilityPort;

final class FakeAuthorizedClientMobility implements AuthorizedMobilityPort
{
    public int $assertions = 0;

    public function assertAuthorized(string $operationId, string $clientId, string $sourceDistributorId, string $destinationDistributorId): void
    {
        $this->assertions++;
    }
}
