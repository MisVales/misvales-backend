<?php

declare(strict_types=1);

namespace App\Modules\Points\Application\Services;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Points\Domain\Exceptions\PointsDomainException;
use App\Modules\Points\Domain\ValueObjects\PointBalance;
use App\Modules\Points\Infrastructure\Persistence\Models\PointAccountModel;
use Illuminate\Support\Facades\DB;

final readonly class PointAccountService
{
    public function __construct(private PointRecorder $recorder) {}

    public function createForDistributor(int $distributorId): PointAccountModel
    {
        return DB::transaction(function () use ($distributorId): PointAccountModel {
            $distributor = User::query()->with('role')->find($distributorId);
            if ($distributor === null || $distributor->role_code !== RoleCode::DISTRIBUTOR->value) {
                throw new PointsDomainException(
                    'POINT_ACCOUNT_NOT_FOUND',
                    'No existe una distribuidora válida para la cuenta solicitada.',
                    404,
                );
            }

            $account = PointAccountModel::query()->firstOrCreate(
                ['distributor_id' => $distributorId],
                [
                    'total_points' => 0,
                    'reserved_points' => 0,
                    'available_points' => 0,
                    'lock_version' => 1,
                ],
            );

            if ($account->wasRecentlyCreated) {
                $this->recorder->audit(
                    'POINT_ACCOUNT_CREATED',
                    'SUCCESS',
                    'point_accounts',
                    (string) $account->id,
                    null,
                    $distributorId,
                    $distributor->branch_id,
                    after: ['total_points' => 0, 'reserved_points' => 0, 'available_points' => 0],
                );
                $this->recorder->outbox('PointAccountCreated', 'account-created:'.$account->id, [
                    'distributor_id' => (string) $distributor->public_id,
                    'branch_id' => $distributor->branch_public_id,
                    'points' => 0,
                ]);
            }

            return $account;
        });
    }

    public function balance(PointAccountModel $account): PointBalance
    {
        return new PointBalance((int) $account->total_points, (int) $account->reserved_points);
    }

    /**
     * Compara saldos sin corregirlos. Una inconsistencia bloquea la cuenta.
     *
     * @return array{consistent: bool, materialized_total: int, ledger_total: int, materialized_reserved: int, active_reserved: int}
     */
    public function reconcile(string $accountId): array
    {
        return DB::transaction(function () use ($accountId): array {
            $account = PointAccountModel::query()->whereKey($accountId)->lockForUpdate()->first();
            if ($account === null) {
                throw new PointsDomainException('POINT_ACCOUNT_NOT_FOUND', 'La cuenta de puntos no existe.', 404);
            }

            $ledgerTotal = (int) DB::table('points_ledger_entries')
                ->where('point_account_id', $accountId)
                ->sum('signed_points');
            $activeReserved = (int) DB::table('point_reservations')
                ->where('point_account_id', $accountId)
                ->where('status', 'ACTIVE')
                ->sum('points');
            $consistent = $ledgerTotal === (int) $account->total_points
                && $activeReserved === (int) $account->reserved_points;

            if (! $consistent) {
                $this->recorder->audit(
                    'POINT_ACCOUNT_INCONSISTENCY_DETECTED',
                    'BLOCKED',
                    'point_accounts',
                    $accountId,
                    null,
                    (int) $account->distributor_id,
                    null,
                    before: [
                        'total_points' => (int) $account->total_points,
                        'reserved_points' => (int) $account->reserved_points,
                    ],
                    after: ['ledger_total' => $ledgerTotal, 'active_reserved' => $activeReserved],
                );
                $this->recorder->outbox('PointAccountInconsistencyDetected', 'account-inconsistent:'.$accountId.':'.now()->timestamp, [
                    'distributor_id' => (string) $account->distributor_id,
                    'point_account_id' => $accountId,
                ]);
            }

            return [
                'consistent' => $consistent,
                'materialized_total' => (int) $account->total_points,
                'ledger_total' => $ledgerTotal,
                'materialized_reserved' => (int) $account->reserved_points,
                'active_reserved' => $activeReserved,
            ];
        });
    }
}
