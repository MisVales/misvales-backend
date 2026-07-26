<?php

declare(strict_types=1);

namespace App\Modules\Points\Application\Services;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Points\Application\DTOs\PointEvaluationOutcome;
use App\Modules\Points\Application\DTOs\RelationLiquidationSnapshot;
use App\Modules\Points\Domain\Enums\LiquidationClassification;
use App\Modules\Points\Domain\Enums\PointLedgerDirection;
use App\Modules\Points\Domain\Enums\PointLedgerType;
use App\Modules\Points\Domain\Enums\RelationPointEvaluationResult;
use App\Modules\Points\Domain\Exceptions\PointsDomainException;
use App\Modules\Points\Domain\Services\LatePaymentPenaltyCalculator;
use App\Modules\Points\Domain\Services\PointEarningCalculator;
use App\Modules\Points\Infrastructure\Persistence\Models\PointAccountModel;
use App\Modules\Points\Infrastructure\Persistence\Models\PointLedgerEntryModel;
use App\Modules\Points\Infrastructure\Persistence\Models\RelationPointEvaluationModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orden de bloqueo: evidencia financiera externa, cuenta, evaluación, solicitud,
 * reserva y movimiento. M13 nunca selecciona vales ni recalcula la clasificación.
 */
final readonly class EvaluateRelationPoints
{
    public function __construct(
        private PointAccountService $accounts,
        private PointEarningCalculator $earning,
        private LatePaymentPenaltyCalculator $penalty,
        private PointRecorder $recorder,
    ) {}

    public function execute(RelationLiquidationSnapshot $snapshot, ?string $pointsRunId = null): PointEvaluationOutcome
    {
        $this->validateIdentity($snapshot);

        if (! $snapshot->classification->isFinal()) {
            return new PointEvaluationOutcome(
                RelationPointEvaluationResult::WAITING_FOR_LIQUIDATION,
                null,
                0,
                0,
                0,
                0,
            );
        }

        try {
            return DB::transaction(function () use ($snapshot, $pointsRunId): PointEvaluationOutcome {
                $existing = RelationPointEvaluationModel::query()
                    ->where('relation_id', $snapshot->relationId)
                    ->first();
                if ($existing !== null) {
                    return $this->existingOutcome($existing);
                }

                $accountId = $this->accounts->createForDistributor($snapshot->distributorId)->id;
                $account = PointAccountModel::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
                $existing = RelationPointEvaluationModel::query()
                    ->where('relation_id', $snapshot->relationId)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    return $this->existingOutcome($existing);
                }
                $before = (int) $account->total_points;
                $earned = 0;
                $penalized = 0;
                $result = RelationPointEvaluationResult::NO_CHANGE_PUNCTUAL;
                $ledgerType = null;
                $configurationVersionId = null;

                if ($snapshot->classification === LiquidationClassification::ANTICIPADA) {
                    if ($snapshot->effectiveLiquidationAt->lt($snapshot->earlyPaymentStartsAt)
                        || $snapshot->effectiveLiquidationAt->gt($snapshot->earlyPaymentEndsAt)) {
                        throw new PointsDomainException(
                            'LIQUIDATION_CLASSIFICATION_INVALID',
                            'La fecha efectiva no pertenece a la ventana anticipada congelada.',
                        );
                    }
                    $earned = $this->earning->calculate(
                        $snapshot->productsCapitalBasis,
                        $snapshot->ruleSnapshot->divisor,
                        $snapshot->ruleSnapshot->multiplier,
                    );
                    $result = $earned > 0
                        ? RelationPointEvaluationResult::EARNED
                        : RelationPointEvaluationResult::NO_CHANGE_ZERO_RESULT;
                    $ledgerType = $earned > 0 ? PointLedgerType::EARNED : null;
                    $configurationVersionId = $snapshot->ruleSnapshot->multiplierVersionId;
                }

                if ($snapshot->classification === LiquidationClassification::FUERA_DE_TIEMPO) {
                    $penalized = $this->penalty->calculate($before, $snapshot->ruleSnapshot->penaltyRate);
                    if ($before - $penalized < (int) $account->reserved_points) {
                        $this->recordBlockedPenalty($snapshot, $account, $penalized);

                        return new PointEvaluationOutcome(
                            RelationPointEvaluationResult::BLOCKED,
                            null,
                            0,
                            0,
                            $before,
                            $before,
                            blockedCode: 'POINT_RESERVATION_CONFLICT',
                        );
                    }
                    $result = $penalized > 0
                        ? RelationPointEvaluationResult::PENALIZED
                        : RelationPointEvaluationResult::NO_CHANGE_ZERO_RESULT;
                    $ledgerType = $penalized > 0 ? PointLedgerType::LATE_PAYMENT_PENALTY : null;
                    $configurationVersionId = $snapshot->ruleSnapshot->penaltyRateVersionId;
                }

                $after = $before + $earned - $penalized;
                $evaluation = RelationPointEvaluationModel::query()->create([
                    'relation_id' => $snapshot->relationId,
                    'distributor_id' => $snapshot->distributorId,
                    'point_account_id' => $account->id,
                    'classification' => $snapshot->classification,
                    'effective_liquidation_at' => $snapshot->effectiveLiquidationAt,
                    'products_capital_basis' => $snapshot->productsCapitalBasis,
                    'divisor_snapshot' => $snapshot->ruleSnapshot->divisor,
                    'multiplier_snapshot' => $snapshot->ruleSnapshot->multiplier,
                    'penalty_rate_snapshot' => $snapshot->ruleSnapshot->penaltyRate,
                    'configuration_version_ids' => [
                        'divisor' => $snapshot->ruleSnapshot->divisorVersionId,
                        'multiplier' => $snapshot->ruleSnapshot->multiplierVersionId,
                        'penalty_rate' => $snapshot->ruleSnapshot->penaltyRateVersionId,
                        'financial_state' => $snapshot->financialStateVersion,
                    ],
                    'balance_before' => $before,
                    'points_earned' => $earned,
                    'points_penalized' => $penalized,
                    'balance_after' => $after,
                    'result' => $result,
                    'source_event_id' => $snapshot->sourceEventId,
                    'points_run_id' => $pointsRunId,
                    'processed_at' => now('UTC'),
                    'created_at' => now('UTC'),
                ]);

                if ($ledgerType !== null) {
                    $this->appendLedger(
                        $snapshot,
                        $account,
                        $evaluation,
                        $ledgerType,
                        $ledgerType === PointLedgerType::EARNED ? $earned : $penalized,
                        $before,
                        $after,
                        $configurationVersionId,
                    );
                    $account->forceFill([
                        'total_points' => $after,
                        'lock_version' => (int) $account->lock_version + 1,
                        'last_movement_at' => now('UTC'),
                    ])->save();
                }

                $eventName = match ($result) {
                    RelationPointEvaluationResult::EARNED => 'PointsEarned',
                    RelationPointEvaluationResult::PENALIZED => 'PointsLatePaymentPenaltyApplied',
                    default => 'PointsEvaluationNoChange',
                };
                [$publicDistributorId, $publicBranchId] = $this->publicIdentities($snapshot);
                $this->recorder->audit(
                    'RELATION_POINTS_EVALUATED',
                    'SUCCESS',
                    'relations',
                    $snapshot->relationId,
                    null,
                    $snapshot->distributorId,
                    $snapshot->branchId,
                    before: ['total_points' => $before, 'reserved_points' => (int) $account->reserved_points],
                    after: ['total_points' => $after, 'result' => $result->value],
                    metadata: ['configuration_versions' => $evaluation->configuration_version_ids],
                    idempotencyKey: $snapshot->sourceEventId,
                );
                $this->recorder->outbox($eventName, 'relation:'.$snapshot->relationId, [
                    'distributor_id' => $publicDistributorId,
                    'branch_id' => $publicBranchId,
                    'relation_id' => $snapshot->relationId,
                    'points' => $earned > 0 ? $earned : $penalized,
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'actor' => 'SYSTEM',
                ]);

                return new PointEvaluationOutcome($result, $evaluation->id, $earned, $penalized, $before, $after);
            }, 3);
        } catch (QueryException $exception) {
            $byRelation = RelationPointEvaluationModel::query()
                ->where('relation_id', $snapshot->relationId)
                ->first();
            if ($byRelation !== null) {
                return $this->existingOutcome($byRelation);
            }
            $byEvent = RelationPointEvaluationModel::query()
                ->where('source_event_id', $snapshot->sourceEventId)
                ->first();
            if ($byEvent !== null) {
                throw new PointsDomainException(
                    'IDEMPOTENCY_KEY_REUSED',
                    'El evento financiero ya fue usado para otra relación.',
                    409,
                );
            }

            throw $exception;
        }
    }

    private function validateIdentity(RelationLiquidationSnapshot $snapshot): void
    {
        if (! Str::isUuid($snapshot->relationId)
            || ! Str::isUuid($snapshot->sourceEventId)
            || $snapshot->financialStateVersion === '') {
            throw new PointsDomainException(
                'LIQUIDATION_CLASSIFICATION_MISSING',
                'El evento financiero no contiene identidad suficiente.',
            );
        }
        if (! $snapshot->isLiquidated && $snapshot->classification->isFinal()) {
            throw new PointsDomainException(
                'RELATION_NOT_LIQUIDATED',
                'La clasificación final exige evidencia de saldo liquidado.',
            );
        }
        if (preg_match('/^-?\d+(?:\.\d{1,4})?$/D', $snapshot->productsCapitalBasis) !== 1
            || bccomp($snapshot->productsCapitalBasis, '0', 4) < 0) {
            throw new PointsDomainException(
                'RELATION_POINT_BASIS_INVALID',
                'La base monetaria de productos no puede ser negativa.',
            );
        }
        foreach ([
            $snapshot->ruleSnapshot->divisorVersionId,
            $snapshot->ruleSnapshot->multiplierVersionId,
            $snapshot->ruleSnapshot->penaltyRateVersionId,
        ] as $versionId) {
            if (! Str::isUuid($versionId)) {
                throw new PointsDomainException(
                    'POINT_CONFIGURATION_NOT_FOUND',
                    'La relación no conserva identificadores de versión válidos.',
                );
            }
        }
    }

    private function existingOutcome(RelationPointEvaluationModel $evaluation): PointEvaluationOutcome
    {
        return new PointEvaluationOutcome(
            $evaluation->result,
            $evaluation->id,
            (int) $evaluation->points_earned,
            (int) $evaluation->points_penalized,
            (int) $evaluation->balance_before,
            (int) $evaluation->balance_after,
            true,
        );
    }

    private function appendLedger(
        RelationLiquidationSnapshot $snapshot,
        PointAccountModel $account,
        RelationPointEvaluationModel $evaluation,
        PointLedgerType $type,
        int $points,
        int $before,
        int $after,
        ?string $configurationVersionId,
    ): void {
        PointLedgerEntryModel::query()->create([
            'point_account_id' => $account->id,
            'distributor_id' => $snapshot->distributorId,
            'type' => $type,
            'direction' => $type === PointLedgerType::EARNED
                ? PointLedgerDirection::CREDIT
                : PointLedgerDirection::DEBIT,
            'points' => $points,
            'signed_points' => $type === PointLedgerType::EARNED ? $points : -$points,
            'balance_before' => $before,
            'balance_after' => $after,
            'reserved_before' => (int) $account->reserved_points,
            'reserved_after' => (int) $account->reserved_points,
            'relation_id' => $snapshot->relationId,
            'point_evaluation_id' => $evaluation->id,
            'rule_code' => $type->value,
            'configuration_version_id' => $configurationVersionId,
            'reason' => $type === PointLedgerType::EARNED
                ? 'Liquidación anticipada definitiva'
                : 'Liquidación fuera de tiempo definitiva',
            'source_event_id' => $snapshot->sourceEventId,
            'branch_id_snapshot' => $snapshot->branchId,
            'actor_type' => 'SYSTEM',
            'actor_id' => null,
            'occurred_at' => $snapshot->effectiveLiquidationAt,
            'created_at' => now('UTC'),
        ]);
    }

    private function recordBlockedPenalty(
        RelationLiquidationSnapshot $snapshot,
        PointAccountModel $account,
        int $penalized,
    ): void {
        [$publicDistributorId, $publicBranchId] = $this->publicIdentities($snapshot);
        $this->recorder->audit(
            'POINTS_EVALUATION_BLOCKED',
            'BLOCKED',
            'relations',
            $snapshot->relationId,
            null,
            $snapshot->distributorId,
            $snapshot->branchId,
            before: [
                'total_points' => (int) $account->total_points,
                'reserved_points' => (int) $account->reserved_points,
            ],
            metadata: ['candidate_penalty' => $penalized],
            idempotencyKey: $snapshot->sourceEventId,
            reason: 'POINT_RESERVATION_CONFLICT',
        );
        $this->recorder->outbox('PointsEvaluationBlocked', 'relation-blocked:'.$snapshot->relationId.':'.$snapshot->sourceEventId, [
            'distributor_id' => $publicDistributorId,
            'branch_id' => $publicBranchId,
            'relation_id' => $snapshot->relationId,
            'points' => $penalized,
            'balance_before' => (int) $account->total_points,
            'balance_after' => (int) $account->total_points,
            'actor' => 'SYSTEM',
            'reason' => 'POINT_RESERVATION_CONFLICT',
        ]);
    }

    /** @return array{string, string} */
    private function publicIdentities(RelationLiquidationSnapshot $snapshot): array
    {
        $distributorId = User::query()->whereKey($snapshot->distributorId)->value('public_id');
        $branchId = Branch::query()->whereKey($snapshot->branchId)->value('public_id');
        if (! is_string($distributorId) || ! is_string($branchId)) {
            throw new PointsDomainException(
                'LIQUIDATION_CLASSIFICATION_INVALID',
                'La evidencia financiera no conserva identidades públicas válidas.',
            );
        }

        return [$distributorId, $branchId];
    }
}
