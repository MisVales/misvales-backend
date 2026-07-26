<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Domain\Services;

use App\Modules\RiskDelinquency\Domain\Enums\FinancialResult;
use App\Modules\RiskDelinquency\Domain\Enums\RelationRiskEvaluationStatus;
use App\Modules\RiskDelinquency\Domain\Exceptions\RiskDelinquencyException;
use App\Modules\RiskDelinquency\Domain\ValueObjects\OverdueBalance;

final class ClassifyRelationRisk
{
    public function classify(FinancialResult $result, OverdueBalance $balance): RelationRiskEvaluationStatus
    {
        if ($result === FinancialResult::LIQUIDO && $balance->isZero()) {
            return RelationRiskEvaluationStatus::COMPLIANT;
        }
        if (in_array($result, [FinancialResult::ABONO, FinancialResult::NO_PAGO], true) && ! $balance->isZero()) {
            return RelationRiskEvaluationStatus::BREACHED;
        }

        throw RiskDelinquencyException::sourceInconsistent();
    }
}
