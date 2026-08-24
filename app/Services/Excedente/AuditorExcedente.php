<?php

namespace App\Services\Excedente;

use App\Helpers\AuditHelper;

final class AuditorExcedente
{
    public function registrar(
        string $evento,
        string $entidad,
        string $entidadId,
        ?string $actorId,
        string $branchId,
        array $datos,
        ?array $anterior = null,
        ?string $motivo = null,
        ?string $autorizadorId = null,
        ?string $ejecutorId = null,
        ?array $evidencia = null,
    ): void {
        AuditHelper::log($evento, $entidad, $entidadId, $actorId, $branchId, $anterior, $datos, $motivo, null, null, $autorizadorId, $ejecutorId, $evidencia);
    }
}
