<?php

namespace App\Modules\Audit\Application\Contracts;

use App\Modules\Audit\Application\DTOs\AuditEventData;

interface AuditRecorder
{
    /**
     * Registra un evento de forma inmutable en la base de datos y/o log estructural.
     */
    public function record(AuditEventData $event): void;
}
