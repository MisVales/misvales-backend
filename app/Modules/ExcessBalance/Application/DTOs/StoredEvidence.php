<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Application\DTOs;

final readonly class StoredEvidence
{
    public function __construct(
        public string $storageFileId,
        public string $sha256,
        public int $sizeBytes,
        public string $detectedMime,
    ) {}
}
