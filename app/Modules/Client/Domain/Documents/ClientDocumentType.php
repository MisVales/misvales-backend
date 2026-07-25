<?php

declare(strict_types=1);

namespace App\Modules\Client\Domain\Documents;

/** Tipos de referencia privada confirmados para el expediente del cliente. */
enum ClientDocumentType: string
{
    case OFFICIAL_IDENTIFICATION = 'OFFICIAL_IDENTIFICATION';
    case ADDRESS_PROOF = 'ADDRESS_PROOF';
}
