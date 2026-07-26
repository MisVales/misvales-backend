<?php

declare(strict_types=1);

namespace App\Modules\Points\Application\DTOs;

use App\Modules\Points\Domain\Enums\RelationPointEvaluationResult;

final readonly class PointEvaluationOutcome
{
    public function __construct(
        public RelationPointEvaluationResult $result,
        public ?string $evaluationId,
        public int $pointsEarned,
        public int $pointsPenalized,
        public int $balanceBefore,
        public int $balanceAfter,
        public bool $alreadyProcessed = false,
        public ?string $blockedCode = null,
    ) {}
}
