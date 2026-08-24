<?php

namespace App\Services\Excedente;

use App\Exceptions\ExcepcionExcedente;
use App\Models\AplicacionExcedente;
use App\Models\ExcedenteDistribuidora;
use App\Models\MediaFileBinding;
use App\Models\RelacionDistribuidora;
use App\Models\SolicitudDevolucionExcedente;
use App\Models\User;
use App\Services\Pago\ServicioAplicacionPago;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ServicioExcedente
{
    public function __construct(
        private readonly ServicioAplicacionPago $payments,
        private readonly AuditorExcedente $auditor,
    ) {}

    public function elegirCredito(ExcedenteDistribuidora $surplus, User $actor): ExcedenteDistribuidora
    {
        return DB::transaction(function () use ($surplus, $actor): ExcedenteDistribuidora {
            $locked = ExcedenteDistribuidora::query()->whereKey($surplus->id)->lockForUpdate()->firstOrFail();
            $this->assertOwner($locked, $actor);
            $this->assertPendingDecision($locked);
            $previous = $this->snapshot($locked);
            $locked->update(['status' => 'CREDIT_BALANCE']);
            $this->auditor->registrar('EXCESS_SELECTED_AS_CREDIT', 'distributor_surplus', $locked->id, $actor->id, $locked->branch_id, $this->payload($locked), $previous);

            return $locked->fresh($this->relations());
        }, 3);
    }

    public function solicitarDevolucion(ExcedenteDistribuidora $surplus, User $actor): SolicitudDevolucionExcedente
    {
        return DB::transaction(function () use ($surplus, $actor): SolicitudDevolucionExcedente {
            $locked = ExcedenteDistribuidora::query()->whereKey($surplus->id)->lockForUpdate()->firstOrFail();
            $this->assertOwner($locked, $actor);
            $this->assertPendingDecision($locked);
            if (bccomp($locked->available_amount, '0', 4) <= 0) {
                throw new ExcepcionExcedente('EXCESS_WITHOUT_AVAILABLE_BALANCE', 'El excedente no tiene saldo disponible para devolución.');
            }

            $amount = $locked->available_amount;
            $previous = $this->snapshot($locked);
            $locked->update(['available_amount' => '0.0000', 'reserved_amount' => $amount, 'status' => 'REFUND_PENDING']);
            $refund = SolicitudDevolucionExcedente::query()->create([
                'surplus_id' => $locked->id,
                'branch_id' => $locked->branch_id,
                'amount' => $amount,
                'requested_by' => $actor->id,
            ]);
            $this->auditor->registrar('REFUND_REQUESTED', 'surplus_refund_request', $refund->id, $actor->id, $locked->branch_id, $this->payload($locked, [
                'refund_request_id' => $refund->id,
                'requested_by' => $actor->id,
                'amount' => $amount,
                'status' => 'REQUESTED',
            ]), $previous);

            return $refund->fresh($this->refundRelations());
        }, 3);
    }

    public function decidir(SolicitudDevolucionExcedente $refund, User $actor, string $decision, string $reason): SolicitudDevolucionExcedente
    {
        return DB::transaction(function () use ($refund, $actor, $decision, $reason): SolicitudDevolucionExcedente {
            $locked = SolicitudDevolucionExcedente::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'REQUESTED') {
                throw new ExcepcionExcedente('REFUND_NOT_PENDING', 'La devolución ya no está pendiente de autorización.');
            }
            if ($locked->requested_by === $actor->id || $actor->hasRole('cashier')) {
                throw new ExcepcionExcedente('REFUND_SELF_AUTHORIZATION_DENIED', 'La cajera o la persona solicitante no puede autorizar esta devolución.', 403);
            }

            $surplus = ExcedenteDistribuidora::query()->whereKey($locked->surplus_id)->lockForUpdate()->firstOrFail();
            $status = $decision === 'AUTHORIZE' ? 'AUTHORIZED' : 'REJECTED';
            $locked->update([
                'status' => $status,
                'decided_by' => $actor->id,
                'decision_reason' => $reason,
                'decided_at' => now(),
                'authorized_at' => $decision === 'AUTHORIZE' ? now() : null,
            ]);
            if ($decision === 'REJECT') {
                $this->releaseReservation($surplus);
            }
            $event = $decision === 'AUTHORIZE' ? 'REFUND_AUTHORIZED' : 'REFUND_REJECTED';
            $this->auditor->registrar($event, 'surplus_refund_request', $locked->id, $actor->id, $locked->branch_id, $this->payload($surplus, [
                'refund_request_id' => $locked->id,
                'requested_by' => $locked->requested_by,
                'authorized_by' => $decision === 'AUTHORIZE' ? $actor->id : null,
                'status' => $status,
                'amount' => $locked->amount,
                'reason' => $reason,
            ]), null, $reason, $decision === 'AUTHORIZE' ? $actor->id : null);

            return $locked->fresh($this->refundRelations());
        }, 3);
    }

    public function cancelar(SolicitudDevolucionExcedente $refund, User $actor, string $reason): SolicitudDevolucionExcedente
    {
        return DB::transaction(function () use ($refund, $actor, $reason): SolicitudDevolucionExcedente {
            $locked = SolicitudDevolucionExcedente::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($locked->requested_by !== $actor->id || $locked->status !== 'REQUESTED') {
                throw new ExcepcionExcedente('REFUND_CANCELLATION_DENIED', 'Solo la distribuidora solicitante puede cancelar una devolución pendiente.', 403);
            }
            $surplus = ExcedenteDistribuidora::query()->whereKey($locked->surplus_id)->lockForUpdate()->firstOrFail();
            $locked->update(['status' => 'CANCELLED', 'cancelled_by' => $actor->id, 'cancellation_reason' => $reason, 'cancelled_at' => now()]);
            $this->releaseReservation($surplus);
            $this->auditor->registrar('REFUND_CANCELLED', 'surplus_refund_request', $locked->id, $actor->id, $locked->branch_id, $this->payload($surplus, [
                'refund_request_id' => $locked->id,
                'requested_by' => $locked->requested_by,
                'status' => 'CANCELLED',
                'amount' => $locked->amount,
                'reason' => $reason,
            ]), null, $reason);

            return $locked->fresh($this->refundRelations());
        }, 3);
    }

    public function completar(SolicitudDevolucionExcedente $refund, User $actor, array $data): SolicitudDevolucionExcedente
    {
        return DB::transaction(function () use ($refund, $actor, $data): SolicitudDevolucionExcedente {
            $locked = SolicitudDevolucionExcedente::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'AUTHORIZED') {
                throw new ExcepcionExcedente('REFUND_NOT_AUTHORIZED', 'La devolución debe estar autorizada antes de registrar su ejecución.');
            }
            if (bccomp($data['amount'], $locked->amount, 4) !== 0) {
                throw new ExcepcionExcedente('REFUND_AMOUNT_IMMUTABLE', 'El importe ejecutado debe coincidir con el importe autorizado.', 422, ['amount' => ['El importe no coincide con la autorización.']]);
            }
            $hasEvidence = MediaFileBinding::query()
                ->where('media_file_id', $data['evidence_media_id'])
                ->where('owner_type', 'surplus_refund_request')
                ->where('owner_id', $locked->id)
                ->where('purpose', 'REFUND_EVIDENCE')
                ->exists();
            if (! $hasEvidence) {
                throw new ExcepcionExcedente('REFUND_EVIDENCE_INVALID', 'El comprobante privado no pertenece a esta devolución.', 422, ['evidence_media_id' => ['El comprobante no corresponde a la devolución.']]);
            }

            $surplus = ExcedenteDistribuidora::query()->whereKey($locked->surplus_id)->lockForUpdate()->firstOrFail();
            if ($surplus->status !== 'REFUND_PENDING' || bccomp($surplus->reserved_amount, $locked->amount, 4) !== 0) {
                throw new ExcepcionExcedente('REFUND_RESERVATION_INVALID', 'La reserva del excedente ya no está disponible para devolución.');
            }
            $executedAt = CarbonImmutable::parse($data['executed_at']);
            $locked->update([
                'status' => 'EXECUTED',
                'executed_by' => $actor->id,
                'execution_method' => $data['method'],
                'execution_reference' => $data['reference'],
                'execution_amount' => $data['amount'],
                'execution_observations' => $data['observations'] ?? null,
                'evidence_media_id' => $data['evidence_media_id'],
                'executed_at' => $executedAt,
            ]);
            $surplus->update(['reserved_amount' => '0.0000', 'status' => 'REFUNDED']);
            $this->auditor->registrar('REFUND_COMPLETED', 'surplus_refund_request', $locked->id, $actor->id, $locked->branch_id, $this->payload($surplus, [
                'refund_request_id' => $locked->id,
                'requested_by' => $locked->requested_by,
                'authorized_by' => $locked->decided_by,
                'executed_by' => $actor->id,
                'amount' => $data['amount'],
                'status' => 'EXECUTED',
                'method' => $data['method'],
                'reference' => $data['reference'],
                'evidence_media_id' => $data['evidence_media_id'],
                'executed_at' => $executedAt->toIso8601String(),
            ]), null, null, $locked->decided_by, $actor->id, ['media_id' => $data['evidence_media_id']]);

            return $locked->fresh($this->refundRelations());
        }, 3);
    }

    public function aplicarDisponibles(RelacionDistribuidora $relation): void
    {
        $ids = ExcedenteDistribuidora::query()
            ->where('distributor_id', $relation->distributor_id)
            ->whereIn('status', ['CREDIT_BALANCE', 'PARTIALLY_APPLIED'])
            ->where('available_amount', '>', 0)
            ->oldest()
            ->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $relation): void {
                $surplus = ExcedenteDistribuidora::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                $lockedRelation = RelacionDistribuidora::query()->whereKey($relation->id)->lockForUpdate()->firstOrFail();
                if (! in_array($surplus->status, ['CREDIT_BALANCE', 'PARTIALLY_APPLIED'], true)
                    || bccomp($surplus->available_amount, '0', 4) <= 0
                    || bccomp($lockedRelation->balance, '0', 4) <= 0) {
                    return;
                }

                $amount = bccomp($surplus->available_amount, $lockedRelation->balance, 4) > 0 ? $lockedRelation->balance : $surplus->available_amount;
                $before = $surplus->available_amount;
                $after = bcsub($before, $amount, 4);
                $idempotencyKey = 'surplus:'.$surplus->id.':relation:'.$lockedRelation->id;
                $application = AplicacionExcedente::query()->firstOrCreate(
                    ['idempotency_key' => $idempotencyKey],
                    [
                        'surplus_id' => $surplus->id,
                        'relation_id' => $lockedRelation->id,
                        'amount' => $amount,
                        'balance_before' => $before,
                        'balance_after' => $after,
                        'process' => 'RELATION_GENERATION',
                        'applied_at' => now(),
                    ],
                );
                if (! $application->wasRecentlyCreated) {
                    return;
                }

                $payment = $this->payments->aplicarSaldoFavor($amount, now()->toImmutable(), $lockedRelation, $application->id);
                $application->update(['payment_id' => $payment->id]);
                $surplus->update(['available_amount' => $after, 'status' => bccomp($after, '0', 4) === 0 ? 'CONSUMED' : 'PARTIALLY_APPLIED']);
                $this->auditor->registrar('EXCESS_APPLIED', 'surplus_application', $application->id, null, $surplus->branch_id, $this->payload($surplus, [
                    'application_id' => $application->id,
                    'destination_relation_id' => $lockedRelation->id,
                    'payment_id' => $payment->id,
                    'amount' => $amount,
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'process' => 'RELATION_GENERATION',
                    'idempotency_key' => $idempotencyKey,
                    'capital_applied' => $payment->capital_applied,
                    'line_recovered' => $payment->line_recovered,
                ]), ['available_amount' => $before]);
            }, 3);
        }
    }

    private function assertOwner(ExcedenteDistribuidora $surplus, User $actor): void
    {
        if ($actor->distribuidora?->id !== $surplus->distributor_id) {
            throw new ExcepcionExcedente('EXCESS_OWNER_SCOPE_DENIED', 'No puedes operar excedentes de otra distribuidora.', 403);
        }
    }

    private function assertPendingDecision(ExcedenteDistribuidora $surplus): void
    {
        if ($surplus->status !== 'PENDING_DECISION') {
            throw new ExcepcionExcedente('EXCESS_ALREADY_DECIDED', 'El excedente ya tiene una decisión registrada.');
        }
    }

    private function releaseReservation(ExcedenteDistribuidora $surplus): void
    {
        $surplus->update([
            'available_amount' => bcadd($surplus->available_amount, $surplus->reserved_amount, 4),
            'reserved_amount' => '0.0000',
            'status' => 'PENDING_DECISION',
        ]);
    }

    private function payload(ExcedenteDistribuidora $surplus, array $extra = []): array
    {
        return array_merge([
            'surplus_id' => $surplus->id,
            'distributor_id' => $surplus->distributor_id,
            'branch_id' => $surplus->branch_id,
            'relation_id' => $surplus->origin_relation_id,
            'bank_movement_id' => $surplus->bank_movement_id,
            'original_amount' => $surplus->original_amount,
            'available_amount' => $surplus->available_amount,
            'reserved_amount' => $surplus->reserved_amount,
            'status' => $surplus->status,
        ], $extra);
    }

    private function snapshot(ExcedenteDistribuidora $surplus): array
    {
        return ['available_amount' => $surplus->available_amount, 'reserved_amount' => $surplus->reserved_amount, 'status' => $surplus->status];
    }

    private function relations(): array
    {
        return ['distributor.usuario', 'branch', 'originRelation', 'bankMovement', 'applications.relation', 'refundRequests'];
    }

    private function refundRelations(): array
    {
        return ['surplus.distributor.usuario', 'surplus.originRelation', 'surplus.bankMovement', 'branch', 'requester', 'decisionMaker', 'executor', 'evidence'];
    }
}
