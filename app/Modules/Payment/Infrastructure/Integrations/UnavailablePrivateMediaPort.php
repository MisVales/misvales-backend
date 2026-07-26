<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Integrations;

use App\Modules\Payment\Application\Contracts\PrivateMediaPort;
use App\Modules\Payment\Domain\Exceptions\PaymentDomainException;
use Illuminate\Http\UploadedFile;

/** Impide persistir archivos fuera del mecanismo privado e inmutable de M18. */
final class UnavailablePrivateMediaPort implements PrivateMediaPort
{
    public function storeBankImport(UploadedFile $file): string
    {
        throw PaymentDomainException::mediaContractUnavailable();
    }

    public function storeEvidence(UploadedFile $file): string
    {
        throw PaymentDomainException::mediaContractUnavailable();
    }
}
