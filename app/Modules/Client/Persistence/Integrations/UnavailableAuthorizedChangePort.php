<?php

declare(strict_types=1);

namespace App\Modules\Client\Persistence\Integrations;

use App\Modules\Client\Application\Contracts\AuthorizedChangePort;
use App\Modules\Client\Application\Contracts\AuthorizedClientChange;
use App\Modules\Client\Application\Contracts\ConsumedChangeAuthorization;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;

/** Deniega cambios sensibles hasta que M09 implemente el consumo transaccional. */
final class UnavailableAuthorizedChangePort implements AuthorizedChangePort
{
    public function consume(AuthorizedClientChange $change): ConsumedChangeAuthorization
    {
        throw ClientDomainException::integrationUnavailable('M09_AUTHORIZED_CHANGE');
    }
}
