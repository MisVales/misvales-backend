<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Application\Services;

use App\Models\User;
use App\Modules\RiskDelinquency\Application\Contracts\OverdueBalancePort;
use App\Modules\RiskDelinquency\Application\Contracts\RiskClock;
use App\Modules\RiskDelinquency\Domain\Enums\DelinquencyStatus;
use App\Modules\RiskDelinquency\Domain\Enums\RemovalRequestStatus;
use App\Modules\RiskDelinquency\Domain\Enums\RiskAlertStatus;
use App\Modules\RiskDelinquency\Domain\Enums\RiskSequenceStatus;
use App\Modules\RiskDelinquency\Domain\ValueObjects\OverdueBalance;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DelinquencyRemovalRequest;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DistributorRiskProfile;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\RiskAlert;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\RiskSequence;
use Illuminate\Support\Facades\DB;

/** Consume cambios conciliados de M11; jamás retira morosidad automáticamente. */
final class DetectFinancialRegularization
{
    public function __construct(
        private readonly OverdueBalancePort $balances,
        private readonly RiskClock $clock,
        private readonly RiskRecorder $recorder,
    ) {}

    public function detect(int $distributorId, string $sourceVersion): DistributorRiskProfile
    {
        return DB::transaction(function () use ($distributorId, $sourceVersion): DistributorRiskProfile {
            $profile = DistributorRiskProfile::query()
                ->where('distributor_id', $distributorId)
                ->lockForUpdate()
                ->firstOrFail();
            $distributorPublicId = (string) User::query()->whereKey($distributorId)->value('public_id');
            $balance = new OverdueBalance($this->balances->totalForDistributor($distributorId));
            $before = [
                'state' => $profile->delinquency_status->value,
                'overdue_balance' => $profile->overdue_balance,
                'consecutive_breaches' => $profile->consecutive_breaches,
            ];

            if (! $balance->isZero()) {
                $profile->forceFill([
                    'overdue_balance' => $balance->value,
                    'financially_regularized_at' => null,
                    'lock_version' => $profile->lock_version + 1,
                ])->save();
                $requests = DelinquencyRemovalRequest::query()
                    ->where('distributor_id', $distributorId)
                    ->where('status', RemovalRequestStatus::PREPARED->value)
                    ->lockForUpdate()
                    ->get();
                foreach ($requests as $request) {
                    $request->forceFill([
                        'status' => RemovalRequestStatus::INVALIDATED,
                        'invalidated_at' => $this->clock->nowUtc(),
                        'lock_version' => $request->lock_version + 1,
                    ])->save();
                    $this->recorder->record(
                        'DelinquencyRemovalInvalidated',
                        'removal_request',
                        $request->id,
                        $distributorId,
                        $request->branch_id,
                        before: ['state' => RemovalRequestStatus::PREPARED->value],
                        after: ['state' => RemovalRequestStatus::INVALIDATED->value],
                        reason: 'OVERDUE_BALANCE_REAPPEARED',
                    );
                    $this->recorder->outbox('DelinquencyRemovalInvalidated', $request->id, $request->id, [
                        'request_id' => $request->request_number,
                    ]);
                }

                return $profile;
            }

            RiskSequence::query()
                ->where('distributor_id', $distributorId)
                ->where('status', RiskSequenceStatus::ACTIVE->value)
                ->update([
                    'status' => RiskSequenceStatus::RESET_BY_REGULARIZATION->value,
                    'reset_reason' => 'OVERDUE_BALANCE_ZERO',
                    'regularized_at' => $this->clock->nowUtc(),
                    'updated_at' => $this->clock->nowUtc(),
                ]);
            RiskAlert::query()
                ->where('distributor_id', $distributorId)
                ->where('status', RiskAlertStatus::ACTIVE->value)
                ->update([
                    'status' => RiskAlertStatus::FINANCIALLY_REGULARIZED->value,
                    'resolved_at' => $this->clock->nowUtc(),
                    'updated_at' => $this->clock->nowUtc(),
                ]);
            $next = $profile->delinquency_status === DelinquencyStatus::NOT_DELINQUENT
                ? DelinquencyStatus::NOT_DELINQUENT
                : DelinquencyStatus::REGULARIZED_PENDING_REMOVAL;
            $profile->forceFill([
                'overdue_balance' => '0.0000',
                'consecutive_breaches' => 0,
                'financially_regularized_at' => $this->clock->nowUtc(),
                'delinquency_status' => $next,
                'blocked_for_new_vouchers' => $next->blocksVoucherIssuance(),
                'lock_version' => $profile->lock_version + 1,
            ])->save();
            $after = [
                'state' => $profile->delinquency_status->value,
                'overdue_balance' => $profile->overdue_balance,
                'consecutive_breaches' => 0,
            ];
            $this->recorder->record(
                'DistributorFinanciallyRegularized',
                'risk_profile',
                $profile->id,
                $distributorId,
                $profile->current_branch_id,
                before: $before,
                after: $after,
                metadata: ['source_version' => $sourceVersion],
                idempotencyKey: $sourceVersion,
            );
            $this->recorder->outbox('RegularizationPaymentDetected', $sourceVersion.':payment', $profile->id, [
                'distributor_id' => $distributorPublicId,
            ]);
            $this->recorder->outbox('DistributorFinanciallyRegularized', $sourceVersion, $profile->id, [
                'distributor_id' => $distributorPublicId,
                'delinquency_continues' => $next !== DelinquencyStatus::NOT_DELINQUENT,
            ]);
            $this->recorder->outbox('DistributorRiskSequenceReset', $sourceVersion.':sequence', $profile->id, [
                'distributor_id' => $distributorPublicId,
                'reason' => 'FINANCIAL_REGULARIZATION',
            ]);

            return $profile;
        }, 3);
    }
}
