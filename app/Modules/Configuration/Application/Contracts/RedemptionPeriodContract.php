<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\Contracts;

use Carbon\CarbonImmutable;

/**
 * Contrato de verificación de periodo de canje para M13.
 *
 * Sin periodo vigente, el canje permanece cerrado.
 */
interface RedemptionPeriodContract
{
    /**
     * Verifica si existe un periodo de canje publicado y vigente.
     */
    public function isRedemptionOpen(CarbonImmutable $at): bool;

    /**
     * Obtiene el periodo de canje vigente.
     *
     * @return array{period_id: string, starts_at: string, ends_at: string}|null
     */
    public function getActivePeriod(CarbonImmutable $at): ?array;
}
