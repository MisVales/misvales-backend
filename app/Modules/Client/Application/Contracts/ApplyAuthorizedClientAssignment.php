<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

/** Punto de entrada interno que M15 invoca tras completar sus autorizaciones. */
interface ApplyAuthorizedClientAssignment
{
    public function handle(ApplyAuthorizedClientAssignmentCommand $command): void;
}
