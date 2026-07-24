<?php

namespace App\Modules\Access\Domain\Security;

final readonly class RiskAssessment
{
    /**
     * @param  list<string>  $matchedRules
     */
    public function __construct(
        public RiskLevel $level,
        public RiskResponse $response,
        public array $matchedRules,
        public int $score,
    ) {}
}
