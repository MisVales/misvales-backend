<?php

namespace App\Modules\Access\Domain\Authorization;

/** Consulta asignaciones organizacionales mediante un contrato estable para M01. */
interface AssignmentStatusProvider
{
    /** Obtiene el estado de la asignación de una cuenta. */
    public function forUser(int $userId): AssignmentStatus;
}
