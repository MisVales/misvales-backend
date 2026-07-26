<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Infrastructure\Integrations;

use App\Modules\RiskDelinquency\Application\Contracts\RelationRiskSourcePort;
use App\Modules\RiskDelinquency\Application\DTOs\RelationPostDueEvaluation;
use App\Modules\RiskDelinquency\Domain\Exceptions\RiskDelinquencyException;

/** Cierra la evaluación hasta que M10/M11 publiquen su contrato definitivo. */
final class UnavailableRelationRiskSource implements RelationRiskSourcePort
{
    public function definitiveEvaluation(string $relationId): RelationPostDueEvaluation
    {
        throw RiskDelinquencyException::sourceUnavailable();
    }

    public function review(string $relationId): array
    {
        throw RiskDelinquencyException::sourceUnavailable();
    }

    public function missingEvaluations(): array
    {
        throw RiskDelinquencyException::sourceUnavailable();
    }
}
