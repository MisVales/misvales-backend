<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Integrations;

use App\Modules\Payment\Application\Contracts\BankFileContract;
use App\Modules\Payment\Domain\Exceptions\PaymentDomainException;
use Illuminate\Http\UploadedFile;

/** Bloqueo deliberado hasta que se publique el formato bancario exacto. */
final class UnavailableBankFileContract implements BankFileContract
{
    public function assertAccepted(UploadedFile $file): void
    {
        throw PaymentDomainException::fileContractUnavailable();
    }
}
