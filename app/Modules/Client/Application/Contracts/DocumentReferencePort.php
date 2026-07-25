<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

/** Autoriza una referencia privada sin exponer contenido ni URL pública. */
interface DocumentReferencePort
{
    public function assertAvailableToActor(string $mediaId, int $actorUserId): DocumentReference;
}
