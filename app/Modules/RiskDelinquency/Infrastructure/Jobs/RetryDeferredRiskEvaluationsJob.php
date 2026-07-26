<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Infrastructure\Jobs;

use App\Modules\RiskDelinquency\Application\Contracts\RelationRiskSourcePort;
use App\Modules\RiskDelinquency\Application\Services\ConsumeRelationPostDueEvaluation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RetryDeferredRiskEvaluationsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function handle(RelationRiskSourcePort $source, ConsumeRelationPostDueEvaluation $consumer): void
    {
        foreach ($source->missingEvaluations() as $evaluation) {
            $consumer->consume($evaluation);
        }
    }
}
