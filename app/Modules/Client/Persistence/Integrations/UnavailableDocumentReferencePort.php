<?php

declare(strict_types=1);

namespace App\Modules\Client\Persistence\Integrations;

use App\Modules\Client\Application\Contracts\DocumentReference;
use App\Modules\Client\Application\Contracts\DocumentReferencePort;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;

/** Mantiene privadas y no asumidas las referencias mientras M18 no esté disponible. */
final class UnavailableDocumentReferencePort implements DocumentReferencePort
{
    public function assertAvailableToActor(string $mediaId, int $actorUserId): DocumentReference
    {
        throw ClientDomainException::integrationUnavailable('M18_PRIVATE_MEDIA');
    }
}
