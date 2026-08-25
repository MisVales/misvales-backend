<?php

namespace App\Services\Puntos;

use App\Models\Distribuidora;
use App\Models\PointAccount;
use App\Models\PointMovement;
use App\Models\PointRedemptionRequest;
use App\Models\RelacionDistribuidora;
use App\Models\User;
use App\Services\ConfiguracionServicio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ServicioCanjePuntos
{
    public function __construct(
        private readonly ?ConfiguracionServicio $configuracionServicio = null
    ) {}

    public function obtenerValorPuntoVigente(): string
    {
        if ($this->configuracionServicio) {
            try {
                $config = $this->configuracionServicio->resolver('POINT_VALUE_AMOUNT');
                if (! empty($config['value'])) {
                    return bcadd((string) $config['value'], '0', 4);
                }
            } catch (\Throwable $e) {
                // Fallback default
            }
        }

        return '2.0000';
    }

    public function obtenerOCrearCuenta(Distribuidora $distribuidora): PointAccount
    {
        return PointAccount::firstOrCreate(
            ['distributor_id' => $distribuidora->id],
            ['balance' => 0, 'reserved' => 0, 'lock_version' => 0]
        );
    }

    public function consultarResumen(Distribuidora $distribuidora): array
    {
        $account = $this->obtenerOCrearCuenta($distribuidora);
        $pointValue = $this->obtenerValorPuntoVigente();
        $availablePoints = $account->puntosDisponibles();
        $moneyEquivalent = bcmul((string) $availablePoints, $pointValue, 4);
        $totalMoneyEquivalent = bcmul((string) $account->balance, $pointValue, 4);

        return [
            'account_id' => $account->id,
            'distributor_id' => $distribuidora->id,
            'distributor_number' => $distribuidora->distributor_number,
            'distributor_name' => $distribuidora->usuario?->name,
            'balance' => $account->balance,
            'reserved' => $account->reserved,
            'available_points' => $availablePoints,
            'point_value' => $pointValue,
            'money_equivalent' => $moneyEquivalent,
            'total_money_equivalent' => $totalMoneyEquivalent,
        ];
    }

    public function solicitarCanje(Distribuidora $distribuidora, int $points, User $actor): PointRedemptionRequest
    {
        if ($points <= 0) {
            throw new RuntimeException('INVALID_POINTS_AMOUNT');
        }

        return DB::transaction(function () use ($distribuidora, $points, $actor): PointRedemptionRequest {
            $account = PointAccount::where('distributor_id', $distribuidora->id)->lockForUpdate()->first();
            if (! $account) {
                $account = PointAccount::create([
                    'distributor_id' => $distribuidora->id,
                    'balance' => 0,
                    'reserved' => 0,
                    'lock_version' => 0,
                ]);
            }

            $available = max(0, $account->balance - $account->reserved);
            if ($points > $available) {
                throw new RuntimeException('INSUFFICIENT_POINTS_BALANCE');
            }

            $pointValue = $this->obtenerValorPuntoVigente();
            $totalAmount = bcmul((string) $points, $pointValue, 4);

            $account->reserved += $points;
            $account->lock_version++;
            $account->save();

            $redemption = PointRedemptionRequest::create([
                'id' => (string) Str::uuid(),
                'point_account_id' => $account->id,
                'distributor_id' => $distribuidora->id,
                'points' => $points,
                'point_value_snapshot' => $pointValue,
                'total_amount' => $totalAmount,
                'status' => 'REQUESTED',
                'balance_before' => $account->balance,
                'balance_after' => null,
                'requested_by' => $actor->id,
                'requested_at' => now(),
            ]);

            PointMovement::create([
                'id' => (string) Str::uuid(),
                'point_account_id' => $account->id,
                'distributor_id' => $distribuidora->id,
                'type' => 'RESERVE',
                'points' => -$points,
                'point_value_snapshot' => $pointValue,
                'amount' => $totalAmount,
                'balance_before' => $account->balance,
                'balance_after' => $account->balance,
                'source_type' => 'POINT_REDEMPTION',
                'source_id' => $redemption->id,
                'performed_by' => $actor->id,
                'created_at' => now(),
            ]);

            return $redemption->fresh(['distribuidora.usuario', 'solicitante']);
        });
    }

    public function autorizarCanje(PointRedemptionRequest $redemption, User $actor): PointRedemptionRequest
    {
        return DB::transaction(function () use ($redemption, $actor): PointRedemptionRequest {
            $locked = PointRedemptionRequest::whereKey($redemption->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'REQUESTED') {
                throw new RuntimeException('INVALID_REDEMPTION_STATUS');
            }

            $locked->update([
                'status' => 'AUTHORIZED',
                'authorized_by' => $actor->id,
                'authorized_at' => now(),
            ]);

            return $locked->fresh(['distribuidora.usuario', 'solicitante', 'autorizador']);
        });
    }

    public function rechazarCanje(PointRedemptionRequest $redemption, User $actor, string $rejectionReason): PointRedemptionRequest
    {
        return DB::transaction(function () use ($redemption, $actor, $rejectionReason): PointRedemptionRequest {
            $locked = PointRedemptionRequest::whereKey($redemption->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'REQUESTED') {
                throw new RuntimeException('INVALID_REDEMPTION_STATUS');
            }

            $account = PointAccount::whereKey($locked->point_account_id)->lockForUpdate()->firstOrFail();
            $account->reserved = max(0, $account->reserved - $locked->points);
            $account->lock_version++;
            $account->save();

            $locked->update([
                'status' => 'REJECTED',
                'authorized_by' => $actor->id,
                'authorized_at' => now(),
                'rejection_reason' => $rejectionReason,
            ]);

            PointMovement::create([
                'id' => (string) Str::uuid(),
                'point_account_id' => $account->id,
                'distributor_id' => $locked->distributor_id,
                'type' => 'RELEASE',
                'points' => $locked->points,
                'point_value_snapshot' => $locked->point_value_snapshot,
                'amount' => $locked->total_amount,
                'balance_before' => $account->balance,
                'balance_after' => $account->balance,
                'source_type' => 'POINT_REDEMPTION',
                'source_id' => $locked->id,
                'performed_by' => $actor->id,
                'created_at' => now(),
            ]);

            return $locked->fresh(['distribuidora.usuario', 'solicitante', 'autorizador']);
        });
    }

    public function entregarCanje(PointRedemptionRequest $redemption, User $actor, ?string $deliveryNotes = null): PointRedemptionRequest
    {
        return DB::transaction(function () use ($redemption, $actor, $deliveryNotes): PointRedemptionRequest {
            $locked = PointRedemptionRequest::whereKey($redemption->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'AUTHORIZED') {
                throw new RuntimeException('REDEMPTION_NOT_AUTHORIZED');
            }

            $account = PointAccount::whereKey($locked->point_account_id)->lockForUpdate()->firstOrFail();
            $balanceBefore = $account->balance;
            $account->balance = max(0, $account->balance - $locked->points);
            $account->reserved = max(0, $account->reserved - $locked->points);
            $balanceAfter = $account->balance;
            $account->lock_version++;
            $account->save();

            $locked->update([
                'status' => 'DELIVERED',
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'delivered_by' => $actor->id,
                'delivered_at' => now(),
                'delivery_notes' => $deliveryNotes,
            ]);

            PointMovement::create([
                'id' => (string) Str::uuid(),
                'point_account_id' => $account->id,
                'distributor_id' => $locked->distributor_id,
                'type' => 'REDEMPTION',
                'points' => -$locked->points,
                'point_value_snapshot' => $locked->point_value_snapshot,
                'amount' => $locked->total_amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'source_type' => 'POINT_REDEMPTION',
                'source_id' => $locked->id,
                'performed_by' => $actor->id,
                'created_at' => now(),
            ]);

            return $locked->fresh(['distribuidora.usuario', 'solicitante', 'autorizador', 'entregador']);
        });
    }

    public function acreditarPuntos(Distribuidora $distribuidora, int $points, string $motivo, ?User $actor = null): PointAccount
    {
        return DB::transaction(function () use ($distribuidora, $points, $actor): PointAccount {
            $account = PointAccount::firstOrCreate(
                ['distributor_id' => $distribuidora->id],
                ['balance' => 0, 'reserved' => 0, 'lock_version' => 0]
            );

            $account = PointAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $balanceBefore = $account->balance;
            $account->balance += $points;
            $balanceAfter = $account->balance;
            $account->lock_version++;
            $account->save();

            PointMovement::create([
                'id' => (string) Str::uuid(),
                'point_account_id' => $account->id,
                'distributor_id' => $distribuidora->id,
                'type' => 'ACCREDIT',
                'points' => $points,
                'point_value_snapshot' => $this->obtenerValorPuntoVigente(),
                'amount' => null,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'source_type' => 'MANUAL_CREDIT',
                'source_id' => null,
                'performed_by' => $actor?->id,
                'created_at' => now(),
            ]);

            return $account->fresh();
        });
    }

    public function acreditarLiquidacionAnticipada(RelacionDistribuidora $relacion): ?PointAccount
    {
        if ($relacion->temporal_classification !== 'EARLY') {
            return null;
        }

        $capital = (string) $relacion->pagos()->sum('capital_applied');
        $divisor = $this->valorConfiguracionDecimal('POINTS_DIVISOR_AMOUNT', '1200.0000');
        $multiplicador = (int) $this->valorConfiguracionDecimal('POINTS_MULTIPLIER', '3');
        $puntos = (int) bcdiv($capital, $divisor, 0) * $multiplicador;

        if ($puntos <= 0) {
            return null;
        }

        $account = $this->obtenerOCrearCuenta($relacion->distribuidora);
        $account = PointAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();

        if (PointMovement::query()
            ->where('source_type', 'EARLY_RELATION_SETTLEMENT')
            ->where('source_id', $relacion->id)
            ->exists()) {
            return $account;
        }

        $balanceBefore = $account->balance;
        $account->balance += $puntos;
        $account->lock_version++;
        $account->save();

        PointMovement::query()->create([
            'point_account_id' => $account->id,
            'distributor_id' => $relacion->distributor_id,
            'type' => 'ACCREDIT',
            'points' => $puntos,
            'point_value_snapshot' => $this->obtenerValorPuntoVigente(),
            'amount' => $capital,
            'balance_before' => $balanceBefore,
            'balance_after' => $account->balance,
            'source_type' => 'EARLY_RELATION_SETTLEMENT',
            'source_id' => $relacion->id,
            'performed_by' => null,
            'created_at' => now(),
        ]);

        return $account->fresh();
    }

    private function valorConfiguracionDecimal(string $key, string $fallback): string
    {
        try {
            $value = $this->configuracionServicio?->resolver($key)['value'] ?? $fallback;

            return bcadd((string) $value, '0', 4);
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
