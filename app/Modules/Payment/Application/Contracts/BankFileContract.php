<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Contracts;

use Illuminate\Http\UploadedFile;

/**
 * Contrato productivo del archivo bancario.
 *
 * Debe definir extensión, firma, MIME, hoja/sección, encabezados y límites antes de aceptar una carga.
 */
interface BankFileContract
{
    public function assertAccepted(UploadedFile $file): void;
}
