<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Client\Application\Contracts\AuthorizedChangePort;
use App\Modules\Client\Application\Contracts\AuthorizedClientChange;
use App\Modules\Client\Application\Contracts\ConsumedChangeAuthorization;

final class FakeAuthorizedClientChanges implements AuthorizedChangePort
{
    public int $consumed = 0;

    public function consume(AuthorizedClientChange $change): ConsumedChangeAuthorization
    {
        $this->consumed++;

        return new ConsumedChangeAuthorization($change->cashierUserId, $change->cashierUserId);
    }
}
