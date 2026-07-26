<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Application\Contracts;

use App\Modules\ExcessBalance\Application\DTOs\StoredEvidence;
use Illuminate\Http\UploadedFile;

interface PrivateEvidencePort
{
    public function store(UploadedFile $file, string $operationKey): StoredEvidence;

    public function temporaryAccess(string $storageFileId, int $actorId): string;
}
