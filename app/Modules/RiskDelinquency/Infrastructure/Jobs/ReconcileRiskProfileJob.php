<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Infrastructure\Jobs;

use App\Models\User;
use App\Modules\RiskDelinquency\Application\Services\RiskRecorder;
use App\Modules\RiskDelinquency\Domain\Enums\RiskProfileStatus;
use App\Modules\RiskDelinquency\Domain\Enums\RiskSequenceStatus;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DistributorRiskProfile;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\RiskSequence;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Detecta diferencias y marca revisión; nunca corrige decisiones silenciosamente. */
final class ReconcileRiskProfileJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $distributorId) {}

    public function handle(RiskRecorder $recorder): void
    {
        DB::transaction(function () use ($recorder): void {
            $profile = DistributorRiskProfile::query()
                ->where('distributor_id', $this->distributorId)
                ->lockForUpdate()
                ->first();
            if ($profile === null) {
                return;
            }
            $sequence = RiskSequence::query()
                ->where('distributor_id', $this->distributorId)
                ->where('status', RiskSequenceStatus::ACTIVE->value)
                ->latest('created_at')
                ->first();
            $expectedCount = $sequence?->relations()->count() ?? 0;
            $expectedBlock = $profile->delinquency_status->blocksVoucherIssuance();
            if ($profile->consecutive_breaches === $expectedCount
                && $profile->blocked_for_new_vouchers === $expectedBlock) {
                return;
            }
            $profile->forceFill([
                'profile_status' => RiskProfileStatus::INCONSISTENT,
                'lock_version' => $profile->lock_version + 1,
            ])->save();
            $recorder->record(
                'DistributorRiskProfileInconsistent',
                'risk_profile',
                $profile->id,
                $this->distributorId,
                $profile->current_branch_id,
                after: [
                    'state' => RiskProfileStatus::INCONSISTENT->value,
                    'materialized_breaches' => $profile->consecutive_breaches,
                    'history_breaches' => $expectedCount,
                    'materialized_block' => $profile->blocked_for_new_vouchers,
                    'expected_block' => $expectedBlock,
                ],
            );
            $recorder->outbox('DistributorRiskProfileInconsistent', $profile->id.':'.$profile->lock_version, $profile->id, [
                'distributor_id' => (string) User::query()
                    ->whereKey($this->distributorId)
                    ->value('public_id'),
            ]);
        });
    }
}
