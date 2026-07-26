<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Application\Contracts;

use App\Modules\RiskDelinquency\Application\DTOs\RelationPostDueEvaluation;

/** Frontera de lectura M10/M11; M14 no reconstruye conciliaciones. */
interface RelationRiskSourcePort
{
    public function definitiveEvaluation(string $relationId): RelationPostDueEvaluation;

    /** @return array<string, mixed> Partidas, pagos, conciliaciones, saldo y recargos autorizados. */
    public function review(string $relationId): array;

    /** @return list<RelationPostDueEvaluation> */
    public function missingEvaluations(): array;
}
