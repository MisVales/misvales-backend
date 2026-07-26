<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Application\Services;

use App\Models\User;
use App\Modules\RiskDelinquency\Application\Contracts\DistributorStatusPort;
use App\Modules\RiskDelinquency\Application\Contracts\RiskClock;
use App\Modules\RiskDelinquency\Application\DTOs\RelationPostDueEvaluation;
use App\Modules\RiskDelinquency\Domain\Enums\RelationRiskEvaluationStatus;
use App\Modules\RiskDelinquency\Domain\Enums\RiskAlertStatus;
use App\Modules\RiskDelinquency\Domain\Enums\RiskAlertType;
use App\Modules\RiskDelinquency\Domain\Enums\RiskProfileStatus;
use App\Modules\RiskDelinquency\Domain\Enums\RiskSequenceStatus;
use App\Modules\RiskDelinquency\Domain\Exceptions\RiskDelinquencyException;
use App\Modules\RiskDelinquency\Domain\Services\ClassifyRelationRisk;
use App\Modules\RiskDelinquency\Domain\ValueObjects\OverdueBalance;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DistributorRiskProfile;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\RelationRiskEvaluation;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\RiskAlert;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\RiskSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Consume una versión definitiva de M11 sin inspeccionar movimientos bancarios. */
final class ConsumeRelationPostDueEvaluation
{
    public function __construct(
        private readonly ClassifyRelationRisk $classifier,
        private readonly DistributorStatusPort $distributors,
        private readonly RiskClock $clock,
        private readonly CreateDistributorRiskProfile $profiles,
        private readonly RebuildDistributorRiskSequence $rebuild,
        private readonly RiskRecorder $recorder,
    ) {}

    public function consume(RelationPostDueEvaluation $input): RelationRiskEvaluation
    {
        return DB::transaction(function () use ($input): RelationRiskEvaluation {
            $key = hash('sha256', $input->relationId.'|'.$input->sourceVersion.'|POST_DUE');
            $existing = RelationRiskEvaluation::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing;
            }

            $distributor = $this->distributors->lock($input->distributorId);
            $existing = RelationRiskEvaluation::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($distributor->branch_id !== $input->branchId) {
                throw RiskDelinquencyException::sourceInconsistent();
            }
            if ($input->dueAt->greaterThanOrEqualTo($this->clock->nowOperational())) {
                throw RiskDelinquencyException::sourceInconsistent();
            }

            $profile = DistributorRiskProfile::query()
                ->where('distributor_id', $distributor->id)
                ->lockForUpdate()
                ->first() ?? $this->profiles->create($distributor);

            $balance = new OverdueBalance($input->overdueBalance);
            $status = $input->sourceReady
                ? $this->classifier->classify($input->result, $balance)
                : RelationRiskEvaluationStatus::PENDING_SOURCE;
            $sequencePosition = $status === RelationRiskEvaluationStatus::BREACHED
                ? ((int) RiskSequence::query()
                    ->where('distributor_id', $input->distributorId)
                    ->where('status', RiskSequenceStatus::ACTIVE->value)
                    ->value('breach_count')) + 1
                : null;
            $previous = RelationRiskEvaluation::query()
                ->where('relation_id', $input->relationId)
                ->latest('evaluated_at')
                ->first();

            $evaluation = RelationRiskEvaluation::query()->create([
                'relation_id' => $input->relationId,
                'distributor_id' => $input->distributorId,
                'branch_id' => $input->branchId,
                'cut_id' => $input->cutId,
                'cut_at' => $input->cutAt,
                'due_at' => $input->dueAt,
                'source_result' => $input->sourceReady ? $input->result : null,
                'overdue_balance_snapshot' => $balance->value,
                'evaluation_status' => $status,
                'source_version' => $input->sourceVersion,
                'sequence_position' => $sequencePosition,
                'evaluated_at' => $input->evaluatedAt,
                'supersedes_id' => $previous?->id,
                'idempotency_key' => $key,
                'created_at' => $this->clock->nowUtc(),
            ]);

            if ($status === RelationRiskEvaluationStatus::PENDING_SOURCE) {
                $this->recorder->record(
                    'RelationRiskEvaluationDeferred',
                    'relation_risk_evaluation',
                    $evaluation->id,
                    $input->distributorId,
                    $input->branchId,
                    metadata: ['relation_id' => $input->relationId, 'source_version' => $input->sourceVersion],
                    idempotencyKey: $key,
                );
                $this->recorder->outbox('RelationRiskEvaluationDeferred', $key, $evaluation->id, [
                    'relation_id' => $input->relationId,
                    'distributor_id' => $distributor->public_id,
                ]);

                return $evaluation;
            }

            if ($previous !== null) {
                $profile->forceFill([
                    'profile_status' => RiskProfileStatus::REBUILD_REQUIRED,
                    'lock_version' => $profile->lock_version + 1,
                ])->save();
                $this->recorder->record(
                    'DistributorRiskSequenceRebuildRequired',
                    'relation_risk_evaluation',
                    $evaluation->id,
                    $input->distributorId,
                    $input->branchId,
                    before: ['source_version' => $previous->source_version],
                    after: ['source_version' => $input->sourceVersion],
                    idempotencyKey: $key,
                );
                $this->rebuild->rebuild($input->distributorId, 'SOURCE_EVALUATION_SUPERSEDED');

                return $evaluation;
            }

            $this->applyToSequence($profile, $evaluation, $distributor, $key);

            return $evaluation;
        }, 3);
    }

    private function applyToSequence(
        DistributorRiskProfile $profile,
        RelationRiskEvaluation $evaluation,
        User $distributor,
        string $key,
    ): void {
        $status = $evaluation->evaluation_status;
        if ($status === RelationRiskEvaluationStatus::COMPLIANT) {
            $sequence = RiskSequence::query()
                ->where('distributor_id', $profile->distributor_id)
                ->where('status', RiskSequenceStatus::ACTIVE->value)
                ->lockForUpdate()
                ->first();
            if ($sequence !== null) {
                $sequence->forceFill([
                    'status' => RiskSequenceStatus::RESET_BY_COMPLIANCE,
                    'reset_reason' => 'COMPLIANT_RELATION',
                    'breaking_relation_id' => $evaluation->relation_id,
                    'version' => $sequence->version + 1,
                ])->save();
            }
            $before = $profile->consecutive_breaches;
            $profile->forceFill([
                'consecutive_breaches' => 0,
                'last_evaluated_relation_id' => $evaluation->relation_id,
                'last_evaluated_at' => $evaluation->evaluated_at,
                'overdue_balance' => $this->currentOverdueBalance($profile->distributor_id),
                'profile_status' => RiskProfileStatus::CURRENT,
                'lock_version' => $profile->lock_version + 1,
            ])->save();
            $this->recorder->record(
                'DistributorRiskSequenceReset',
                'relation_risk_evaluation',
                $evaluation->id,
                $profile->distributor_id,
                $profile->current_branch_id,
                before: ['state' => (string) $before],
                after: ['state' => '0'],
                idempotencyKey: $key,
            );
            $this->recorder->outbox('RelationCompliedAfterDueEvaluation', $key, $evaluation->id, [
                'relation_id' => $evaluation->relation_id,
                'distributor_id' => $distributor->public_id,
            ]);

            return;
        }

        $sequence = RiskSequence::query()
            ->where('distributor_id', $profile->distributor_id)
            ->where('status', RiskSequenceStatus::ACTIVE->value)
            ->lockForUpdate()
            ->first();
        if ($sequence === null) {
            $sequence = RiskSequence::query()->create([
                'distributor_id' => $profile->distributor_id,
                'status' => RiskSequenceStatus::ACTIVE,
                'started_at' => $evaluation->evaluated_at,
                'last_incorporated_at' => $evaluation->evaluated_at,
                'breach_count' => 0,
                'version' => 1,
            ]);
        }
        $position = $sequence->breach_count + 1;
        DB::table('risk_sequence_relations')->insert([
            'id' => (string) Str::uuid(),
            'risk_sequence_id' => $sequence->id,
            'evaluation_id' => $evaluation->id,
            'relation_id' => $evaluation->relation_id,
            'position' => $position,
            'overdue_balance_snapshot' => $evaluation->overdue_balance_snapshot,
            'source_result' => $evaluation->source_result->value,
            'created_at' => $this->clock->nowUtc(),
        ]);
        $sequence->forceFill([
            'breach_count' => $position,
            'last_incorporated_at' => $evaluation->evaluated_at,
            'version' => $sequence->version + 1,
        ])->save();
        $profile->forceFill([
            'consecutive_breaches' => $position,
            'last_evaluated_relation_id' => $evaluation->relation_id,
            'last_evaluated_at' => $evaluation->evaluated_at,
            'overdue_balance' => $this->currentOverdueBalance($profile->distributor_id),
            'financially_regularized_at' => null,
            'profile_status' => RiskProfileStatus::CURRENT,
            'lock_version' => $profile->lock_version + 1,
        ])->save();

        if ($position <= 3) {
            $this->createThresholdAlert($profile, $sequence, $evaluation, $distributor, $position, $key);
        }
    }

    private function createThresholdAlert(
        DistributorRiskProfile $profile,
        RiskSequence $sequence,
        RelationRiskEvaluation $evaluation,
        User $distributor,
        int $position,
        string $sourceKey,
    ): void {
        $type = match ($position) {
            1 => RiskAlertType::FIRST_BREACH,
            2 => RiskAlertType::SECOND_BREACH,
            default => RiskAlertType::THIRD_BREACH,
        };
        $alertKey = hash('sha256', $profile->distributor_id.'|'.$sequence->id.'|'.$position);
        $alert = RiskAlert::query()->firstOrCreate(
            ['idempotency_key' => $alertKey],
            [
                'alert_number' => (string) Str::uuid(),
                'distributor_id' => $profile->distributor_id,
                'branch_id' => $profile->current_branch_id,
                'coordinator_id' => $profile->current_coordinator_id,
                'risk_sequence_id' => $sequence->id,
                'alert_type' => $type,
                'breach_count' => $position,
                'overdue_balance_snapshot' => $profile->overdue_balance,
                'status' => RiskAlertStatus::ACTIVE,
                'detected_at' => $evaluation->evaluated_at,
            ],
        );
        if (! $alert->wasRecentlyCreated) {
            return;
        }
        $relations = DB::table('risk_sequence_relations')
            ->join('relation_risk_evaluations', 'relation_risk_evaluations.id', '=', 'risk_sequence_relations.evaluation_id')
            ->where('risk_sequence_relations.risk_sequence_id', $sequence->id)
            ->orderBy('risk_sequence_relations.position')
            ->limit($position === 3 ? 3 : $position)
            ->get();
        foreach ($relations as $relation) {
            DB::table('risk_alert_relations')->insert([
                'id' => (string) Str::uuid(),
                'risk_alert_id' => $alert->id,
                'evaluation_id' => $relation->evaluation_id,
                'relation_id' => $relation->relation_id,
                'position' => $relation->position,
                'cut_at' => $relation->cut_at,
                'due_at' => $relation->due_at,
                'source_result' => $relation->source_result,
                'overdue_balance_snapshot' => $relation->overdue_balance_snapshot,
                'source_version' => $relation->source_version,
                'created_at' => $this->clock->nowUtc(),
            ]);
        }
        $event = match ($position) {
            1 => 'FirstRelationBreachDetected',
            2 => 'SecondConsecutiveRelationBreachDetected',
            default => 'ThirdConsecutiveRelationBreachDetected',
        };
        $this->recorder->record(
            $event,
            'risk_alert',
            $alert->id,
            $profile->distributor_id,
            $profile->current_branch_id,
            after: ['state' => $alert->status->value, 'breach_count' => $position],
            idempotencyKey: $sourceKey,
        );
        $this->recorder->outbox($event, $alertKey, $alert->id, [
            'alert_id' => $alert->alert_number,
            'distributor_id' => $distributor->public_id,
            'breach_count' => $position,
        ]);
        if ($position === 3) {
            $this->recorder->outbox('DistributorRiskAlertCreated', $alertKey.':created', $alert->id, [
                'alert_id' => $alert->alert_number,
                'distributor_id' => $distributor->public_id,
            ]);
        }
    }

    private function currentOverdueBalance(int $distributorId): string
    {
        $latest = RelationRiskEvaluation::query()
            ->where('distributor_id', $distributorId)
            ->where('evaluation_status', '!=', RelationRiskEvaluationStatus::PENDING_SOURCE->value)
            ->orderBy('evaluated_at')
            ->get()
            ->keyBy('relation_id');

        return $latest->reduce(
            fn (string $carry, RelationRiskEvaluation $item): string => $item->evaluation_status === RelationRiskEvaluationStatus::BREACHED
                ? bcadd($carry, $item->overdue_balance_snapshot, 4)
                : $carry,
            '0.0000',
        );
    }
}
