<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Application\Services;

use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\ExcessBalanceModel;

final readonly class DetectExcessInconsistency
{
    public function __construct(
        private ExcessLedgerRebuilder $rebuilder,
        private ExcessRecorder $recorder,
    ) {}

    public function execute(string $excessId): bool
    {
        $balance = ExcessBalanceModel::query()->whereKey($excessId)->firstOrFail();
        if ($this->rebuilder->isConsistent($balance)) {
            return false;
        }

        $rebuilt = $this->rebuilder->rebuild($balance->id);
        $this->recorder->audit(
            'EXCESS_LEDGER_INCONSISTENCY_DETECTED',
            'INCIDENT',
            'excess_balances',
            $balance->id,
            null,
            $this->recorder->amounts($balance),
            $rebuilt,
            systemBranchId: (int) $balance->branch_id,
        );
        $this->recorder->event('ExcessLedgerInconsistencyDetected', $balance, [
            'materialized' => $this->recorder->amounts($balance),
            'rebuilt' => $rebuilt,
            'correlation_id' => $excessId,
        ], 'ledger-inconsistency:'.$balance->id.':'.$balance->lock_version);

        return true;
    }
}
