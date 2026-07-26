<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Infrastructure\Jobs;

use App\Modules\RiskDelinquency\Application\Contracts\RelationRiskSourcePort;
use App\Modules\RiskDelinquency\Application\Services\ConsumeRelationPostDueEvaluation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ConsumeRelationRiskEvaluationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public readonly string $relationId) {}

    public function handle(RelationRiskSourcePort $source, ConsumeRelationPostDueEvaluation $consumer): void
    {
        $consumer->consume($source->definitiveEvaluation($this->relationId));
    }
}
