<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Contracts;

use Illuminate\Http\UploadedFile;

/** Almacena archivos inmutables y devuelve únicamente un identificador opaco. */
interface PrivateMediaPort
{
    public function storeBankImport(UploadedFile $file): string;

    public function storeEvidence(UploadedFile $file): string;
}
