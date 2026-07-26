<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

/** Lectura mínima de concurrencia publicada por M06. */
interface ClientVersionReader
{
    public function lockVersion(string $clientId): int;
}
