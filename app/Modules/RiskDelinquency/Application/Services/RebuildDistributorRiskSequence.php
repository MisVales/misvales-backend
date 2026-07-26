<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Application\Services;

use App\Models\User;
use App\Modules\RiskDelinquency\Application\Contracts\RiskClock;
use App\Modules\RiskDelinquency\Domain\Enums\RelationRiskEvaluationStatus;
use App\Modules\RiskDelinquency\Domain\Enums\RiskAlertStatus;
use App\Modules\RiskDelinquency\Domain\Enums\RiskAlertType;
use App\Modules\RiskDelinquency\Domain\Enums\RiskProfileStatus;
use App\Modules\RiskDelinquency\Domain\Enums\RiskSequenceStatus;
use App\Modules\RiskDelinquency\Domain\Exceptions\RiskDelinquencyException;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DistributorRiskProfile;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\RelationRiskEvaluation;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\RiskAlert;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\RiskSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Reconstrucción controlada desde las últimas versiones, sin decidir morosidad. */
final class RebuildDistributorRiskSequence
{
    public function __construct(
        private readonly RiskRecorder $recorder,
        private readonly RiskClock $clock,
    ) {}

    public function rebuild(int $distributorId, string $reason): DistributorRiskProfile
    {
        if (trim($reason) === '') {
            throw new RiskDelinquencyException('RISK_SEQUENCE_REBUILD_REQUIRED', 'La reconstrucción requiere un motivo técnico.', 422);
        }

        return DB::transaction(function () use ($distributorId, $reason): DistributorRiskProfile {
            $profile = DistributorRiskProfile::query()
                ->where('distributor_id', $distributorId)
                ->lockForUpdate()
                ->firstOrFail();
            $before = [
                'state' => $profile->profile_status->value,
                'consecutive_breaches' => $profile->consecutive_breaches,
                'overdue_balance' => $profile->overdue_balance,
            ];
            $profile->forceFill([
                'profile_status' => RiskProfileStatus::REBUILDING,
                'lock_version' => $profile->lock_version + 1,
            ])->save();
            RiskSequence::query()
                ->where('distributor_id', $distributorId)
                ->where('status', RiskSequenceStatus::ACTIVE->value)
                ->update([
                    'status' => RiskSequenceStatus::SUPERSEDED->value,
                    'reset_reason' => 'CONTROLLED_REBUILD',
                    'updated_at' => $this->clock->nowUtc(),
                ]);
            RiskAlert::query()
                ->where('distributor_id', $distributorId)
                ->where('status', RiskAlertStatus::ACTIVE->value)
                ->update([
                    'status' => RiskAlertStatus::SUPERSEDED->value,
                    'resolved_at' => $this->clock->nowUtc(),
                    'updated_at' => $this->clock->nowUtc(),
                ]);

            $latest = RelationRiskEvaluation::query()
                ->where('distributor_id', $distributorId)
                ->orderBy('cut_at')
                ->orderBy('due_at')
                ->orderBy('relation_id')
                ->orderBy('evaluated_at')
                ->get()
                ->keyBy('relation_id')
                ->sortBy(fn (RelationRiskEvaluation $item): string => $item->cut_at->format('Y-m-d H:i:s.u').'|'.$item->due_at->format('Y-m-d H:i:s.u').'|'.$item->relation_id);
            $current = [];
            $overdue = '0.0000';
            foreach ($latest as $evaluation) {
                if ($evaluation->evaluation_status === RelationRiskEvaluationStatus::PENDING_SOURCE) {
                    $current = [];

                    continue;
                }
                if ($evaluation->evaluation_status === RelationRiskEvaluationStatus::COMPLIANT) {
                    $current = [];

                    continue;
                }
                $current[] = $evaluation;
                $overdue = bcadd($overdue, $evaluation->overdue_balance_snapshot, 4);
            }

            $sequence = null;
            if ($current !== []) {
                $sequence = RiskSequence::query()->create([
                    'distributor_id' => $distributorId,
                    'status' => RiskSequenceStatus::ACTIVE,
                    'started_at' => $current[0]->evaluated_at,
                    'last_incorporated_at' => end($current)->evaluated_at,
                    'breach_count' => count($current),
                    'reset_reason' => null,
                    'version' => 1,
                ]);
                foreach ($current as $index => $evaluation) {
                    DB::table('risk_sequence_relations')->insert([
                        'id' => (string) Str::uuid(),
                        'risk_sequence_id' => $sequence->id,
                        'evaluation_id' => $evaluation->id,
                        'relation_id' => $evaluation->relation_id,
                        'position' => $index + 1,
                        'overdue_balance_snapshot' => $evaluation->overdue_balance_snapshot,
                        'source_result' => $evaluation->source_result->value,
                        'created_at' => $this->clock->nowUtc(),
                    ]);
                }
                $this->recreateThresholdAlerts($profile, $sequence, $current, $overdue);
            }
            $profile->forceFill([
                'consecutive_breaches' => count($current),
                'last_evaluated_relation_id' => $latest->last()?->relation_id,
                'last_evaluated_at' => $latest->last()?->evaluated_at,
                'overdue_balance' => $overdue,
                'profile_status' => RiskProfileStatus::CURRENT,
                'lock_version' => $profile->lock_version + 1,
            ])->save();
            $this->recorder->record(
                'DistributorRiskSequenceRebuilt',
                'risk_profile',
                $profile->id,
                $distributorId,
                $profile->current_branch_id,
                before: $before,
                after: [
                    'state' => $profile->profile_status->value,
                    'consecutive_breaches' => $profile->consecutive_breaches,
                    'overdue_balance' => $profile->overdue_balance,
                    'sequence_id' => $sequence?->id,
                ],
                reason: $reason,
            );

            return $profile;
        }, 3);
    }

    /** @param list<RelationRiskEvaluation> $evaluations */
    private function recreateThresholdAlerts(
        DistributorRiskProfile $profile,
        RiskSequence $sequence,
        array $evaluations,
        string $overdue,
    ): void {
        $distributorPublicId = (string) User::query()
            ->whereKey($profile->distributor_id)
            ->value('public_id');
        foreach (range(1, min(3, count($evaluations))) as $threshold) {
            $type = match ($threshold) {
                1 => RiskAlertType::FIRST_BREACH,
                2 => RiskAlertType::SECOND_BREACH,
                default => RiskAlertType::THIRD_BREACH,
            };
            $key = hash('sha256', $profile->distributor_id.'|'.$sequence->id.'|'.$threshold);
            $alert = RiskAlert::query()->create([
                'alert_number' => (string) Str::uuid(),
                'distributor_id' => $profile->distributor_id,
                'branch_id' => $profile->current_branch_id,
                'coordinator_id' => $profile->current_coordinator_id,
                'risk_sequence_id' => $sequence->id,
                'alert_type' => $type,
                'breach_count' => $threshold,
                'overdue_balance_snapshot' => $overdue,
                'status' => RiskAlertStatus::ACTIVE,
                'detected_at' => $evaluations[$threshold - 1]->evaluated_at,
                'idempotency_key' => $key,
            ]);
            foreach (array_slice($evaluations, 0, $threshold) as $index => $evaluation) {
                DB::table('risk_alert_relations')->insert([
                    'id' => (string) Str::uuid(),
                    'risk_alert_id' => $alert->id,
                    'evaluation_id' => $evaluation->id,
                    'relation_id' => $evaluation->relation_id,
                    'position' => $index + 1,
                    'cut_at' => $evaluation->cut_at,
                    'due_at' => $evaluation->due_at,
                    'source_result' => $evaluation->source_result->value,
                    'overdue_balance_snapshot' => $evaluation->overdue_balance_snapshot,
                    'source_version' => $evaluation->source_version,
                    'created_at' => $this->clock->nowUtc(),
                ]);
            }
            $event = match ($threshold) {
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
                after: [
                    'state' => RiskAlertStatus::ACTIVE->value,
                    'breach_count' => $threshold,
                    'reconstructed' => true,
                ],
                idempotencyKey: 'rebuild:'.$key,
            );
            $this->recorder->outbox($event, 'rebuild:'.$key, $alert->id, [
                'alert_id' => $alert->alert_number,
                'distributor_id' => $distributorPublicId,
                'breach_count' => $threshold,
                'reconstructed' => true,
            ]);
            if ($threshold === 3) {
                $this->recorder->outbox('DistributorRiskAlertCreated', 'rebuild:'.$key.':created', $alert->id, [
                    'alert_id' => $alert->alert_number,
                    'distributor_id' => $distributorPublicId,
                    'reconstructed' => true,
                ]);
            }
        }
    }
}
