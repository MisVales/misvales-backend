<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\RiskDelinquency\Application\Contracts\RelationRiskSourcePort;
use App\Modules\RiskDelinquency\Application\Services\ConsumeRelationPostDueEvaluation;
use App\Modules\RiskDelinquency\Application\Services\RebuildDistributorRiskSequence;
use App\Modules\RiskDelinquency\Infrastructure\Jobs\ReconcileRiskProfileJob;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DistributorRiskProfile;
use Illuminate\Console\Command;

/** Recuperación segura: no aplica ni retira morosidad. */
final class RecoverRiskDelinquency extends Command
{
    protected $signature = 'risk:recover {--distributor= : Internal distributor id} {--rebuild} {--missing}';

    protected $description = 'Report and recover missing M14 evaluations without managerial decisions.';

    public function handle(
        RelationRiskSourcePort $source,
        ConsumeRelationPostDueEvaluation $consumer,
        RebuildDistributorRiskSequence $rebuild,
    ): int {
        if ((bool) $this->option('missing')) {
            foreach ($source->missingEvaluations() as $evaluation) {
                $consumer->consume($evaluation);
            }
        }
        $id = filter_var($this->option('distributor'), FILTER_VALIDATE_INT);
        if ($id !== false) {
            if ((bool) $this->option('rebuild')) {
                $rebuild->rebuild($id, 'INTERNAL_RECOVERY_COMMAND');
            }
            ReconcileRiskProfileJob::dispatchSync($id);
        }
        $inconsistent = DistributorRiskProfile::query()->where('profile_status', 'INCONSISTENT')->count();
        $this->components->info("Reconciliación concluida; perfiles inconsistentes: {$inconsistent}.");

        return self::SUCCESS;
    }
}
