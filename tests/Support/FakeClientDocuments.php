<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Client\Application\Contracts\DocumentReference;
use App\Modules\Client\Application\Contracts\DocumentReferencePort;

final class FakeClientDocuments implements DocumentReferencePort
{
    public function assertAvailableToActor(string $mediaId, int $actorUserId): DocumentReference
    {
        return new DocumentReference($mediaId, hash('sha256', $mediaId));
    }
}
