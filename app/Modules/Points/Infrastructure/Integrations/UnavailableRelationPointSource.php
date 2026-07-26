<?php

declare(strict_types=1);

namespace App\Modules\Points\Infrastructure\Integrations;

use App\Modules\Points\Application\Contracts\RelationPointSource;
use App\Modules\Points\Application\DTOs\RelationLiquidationSnapshot;
use App\Modules\Points\Domain\Exceptions\PointsDomainException;

/** Adaptador fail-closed hasta que M10 publique su contrato productivo. */
final class UnavailableRelationPointSource implements RelationPointSource
{
    public function findEligible(string $relationId): RelationLiquidationSnapshot
    {
        throw new PointsDomainException(
            'RELATION_POINT_BASIS_MISSING',
            'M10 todavía no publicó la evidencia trazable requerida por M13.',
            409,
        );
    }

    public function pending(int $page, int $perPage): iterable
    {
        throw new PointsDomainException(
            'RELATION_POINT_BASIS_MISSING',
            'La recuperación permanece cerrada hasta integrar el contrato productivo de M10.',
            409,
        );
    }
}
