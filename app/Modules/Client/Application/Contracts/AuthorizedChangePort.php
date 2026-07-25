<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

/** Contrato transaccional de M09 para reservar y consumir una autorización exacta. */
interface AuthorizedChangePort
{
    public function consume(AuthorizedClientChange $change): ConsumedChangeAuthorization;
}
