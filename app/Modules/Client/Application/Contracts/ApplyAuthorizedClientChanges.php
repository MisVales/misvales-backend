<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

/** Punto de entrada interno para cambios sensibles autorizados por M09. */
interface ApplyAuthorizedClientChanges
{
    public function handle(ApplyAuthorizedClientChangesCommand $command): ClientChangeResult;
}
