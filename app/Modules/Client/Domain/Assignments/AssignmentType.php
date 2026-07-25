<?php

declare(strict_types=1);

namespace App\Modules\Client\Domain\Assignments;

/** Origen autorizado de una asignación cliente-distribuidora. */
enum AssignmentType: string
{
    case INITIAL = 'INITIAL';
    case AUTHORIZED_TRANSFER = 'AUTHORIZED_TRANSFER';
}
