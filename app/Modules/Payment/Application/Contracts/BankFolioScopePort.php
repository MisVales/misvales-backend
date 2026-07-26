<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Contracts;

/** Resuelve el ámbito aprobado en el que un folio bancario debe ser único. */
interface BankFolioScopePort
{
    public function scopeFor(int $branchId, string $normalizedFolio): string;
}
