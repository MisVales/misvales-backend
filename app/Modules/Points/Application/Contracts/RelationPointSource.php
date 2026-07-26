<?php

declare(strict_types=1);

namespace App\Modules\Points\Application\Contracts;

use App\Modules\Points\Application\DTOs\RelationLiquidationSnapshot;

/** Puerto de lectura M10/M11 para recuperación por lote. */
interface RelationPointSource
{
    public function findEligible(string $relationId): RelationLiquidationSnapshot;

    /**
     * @return iterable<RelationLiquidationSnapshot>
     */
    public function pending(int $page, int $perPage): iterable;
}
