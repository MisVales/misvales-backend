<?php

declare(strict_types=1);

namespace App\Modules\Points\Infrastructure\Jobs;

use App\Modules\Points\Application\Contracts\RelationPointSource;
use App\Modules\Points\Application\Services\EvaluateRelationPoints;
use App\Modules\Points\Application\Services\PointRecorder;
use App\Modules\Points\Domain\Enums\PointsRunStatus;
use App\Modules\Points\Domain\Enums\RelationPointEvaluationResult;
use App\Modules\Points\Infrastructure\Persistence\Models\PointsRunModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Recuperación paginada. Cada relación se confirma en su propia transacción;
 * un fallo individual no mantiene abierto ni revierte el lote completo.
 */
final class RunPendingPointEvaluationsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $pageSize = 100,
        public readonly string $initiatedByType = 'SYSTEM',
        public readonly ?int $initiatedById = null,
    ) {}

    public function handle(
        RelationPointSource $source,
        EvaluateRelationPoints $evaluator,
        PointRecorder $recorder,
    ): void {
        $run = PointsRunModel::query()->create([
            'public_folio' => (string) Str::uuid(),
            'status' => PointsRunStatus::PROCESSING,
            'started_at' => now('UTC'),
            'initiated_by_type' => $this->initiatedByType,
            'initiated_by_id' => $this->initiatedById,
        ]);
        $recorder->outbox('PointsRunStarted', 'points-run-started:'.$run->id, [
            'points_run_id' => $run->id,
            'actor' => $this->initiatedByType,
        ]);

        $page = 1;
        $processed = $earned = $penalized = $noChange = $blocked = $errors = 0;
        try {
            do {
                $candidates = iterator_to_array($source->pending($page, $this->pageSize), false);
                foreach ($candidates as $candidate) {
                    try {
                        $outcome = $evaluator->execute($candidate, $run->id);
                        $processed++;
                        $earned += $outcome->result === RelationPointEvaluationResult::EARNED ? 1 : 0;
                        $penalized += $outcome->result === RelationPointEvaluationResult::PENALIZED ? 1 : 0;
                        $blocked += $outcome->result === RelationPointEvaluationResult::BLOCKED ? 1 : 0;
                        $noChange += in_array($outcome->result, [
                            RelationPointEvaluationResult::NO_CHANGE_PUNCTUAL,
                            RelationPointEvaluationResult::NO_CHANGE_ZERO_RESULT,
                            RelationPointEvaluationResult::ALREADY_PROCESSED,
                        ], true) ? 1 : 0;
                        $this->item($run->id, $candidate->relationId, $outcome->result->value, $outcome->evaluationId);
                    } catch (Throwable $exception) {
                        $errors++;
                        $this->item($run->id, $candidate->relationId, 'ERROR', null, $exception::class);
                    }
                }
                $page++;
            } while (count($candidates) === $this->pageSize);

            $run->forceFill([
                'status' => $errors > 0 ? PointsRunStatus::COMPLETED_WITH_ERRORS : PointsRunStatus::COMPLETED,
                'finished_at' => now('UTC'),
                'total_candidates' => $processed + $errors,
                'processed_count' => $processed,
                'earned_count' => $earned,
                'penalized_count' => $penalized,
                'no_change_count' => $noChange,
                'blocked_count' => $blocked,
                'error_count' => $errors,
            ])->save();
            $recorder->outbox('PointsRunCompleted', 'points-run-completed:'.$run->id, [
                'points_run_id' => $run->id,
                'actor' => $this->initiatedByType,
            ]);
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => PointsRunStatus::FAILED,
                'finished_at' => now('UTC'),
                'error_summary' => $exception::class,
                'error_count' => $errors + 1,
            ])->save();
            throw $exception;
        }
    }

    private function item(
        string $runId,
        string $relationId,
        string $result,
        ?string $evaluationId,
        ?string $errorCode = null,
    ): void {
        DB::table('points_run_items')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'points_run_id' => $runId,
            'relation_id' => $relationId,
            'result' => $result,
            'point_evaluation_id' => $evaluationId,
            'error_code' => $errorCode,
            'error_message' => $errorCode === null ? null : 'Fallo técnico seguro; consulte la auditoría.',
            'processed_at' => now('UTC'),
        ]);
    }
}
