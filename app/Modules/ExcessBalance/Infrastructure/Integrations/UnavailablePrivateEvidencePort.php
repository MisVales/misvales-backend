<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Infrastructure\Integrations;

use App\Modules\ExcessBalance\Application\Contracts\PrivateEvidencePort;
use App\Modules\ExcessBalance\Application\DTOs\StoredEvidence;
use App\Modules\ExcessBalance\Domain\Exceptions\ExcessBalanceException;
use Illuminate\Http\UploadedFile;

final class UnavailablePrivateEvidencePort implements PrivateEvidencePort
{
    public function store(UploadedFile $file, string $operationKey): StoredEvidence
    {
        throw ExcessBalanceException::evidenceContractUnavailable();
    }

    public function temporaryAccess(string $storageFileId, int $actorId): string
    {
        throw ExcessBalanceException::evidenceContractUnavailable();
    }
}
