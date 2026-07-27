<?php

declare(strict_types=1);

namespace App\Modules\Relation\Domain\Enums;

enum RelationDocumentStatus: string
{
    case PENDIENTE = 'PENDIENTE';
    case GENERANDO = 'GENERANDO';
    case DISPONIBLE = 'DISPONIBLE';
    case FALLIDO = 'FALLIDO';
}
