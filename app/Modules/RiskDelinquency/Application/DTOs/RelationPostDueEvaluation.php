<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Application\DTOs;

use App\Modules\RiskDelinquency\Domain\Enums\FinancialResult;
use Carbon\CarbonImmutable;

/** Snapshot definitivo publicado por M11 y ordenado con datos históricos de M10. */
final readonly class RelationPostDueEvaluation
{
    public function __construct(
        public string $relationId,
        public int $distributorId,
        public int $branchId,
        public string $cutId,
        public CarbonImmutable $cutAt,
        public CarbonImmutable $dueAt,
        public FinancialResult $result,
        public string $overdueBalance,
        public CarbonImmutable $evaluatedAt,
        public string $sourceVersion,
        public bool $sourceReady,
    ) {}
}
