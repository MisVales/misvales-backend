<?php

namespace App\Services\Conciliacion;

use App\Exceptions\ExcepcionConciliacion;
use App\Models\AclaracionPago;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\MediaFile;
use App\Models\MovimientoBancario;
use App\Models\RelacionDistribuidora;
use App\Models\SolicitudConciliacionManual;
use App\Models\User;
use App\Services\Pago\ServicioAplicacionPago;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ServicioConciliacionManual
{
    public function __construct(
        private ServicioAplicacionPago $payments,
        private AuditorConciliacion $auditor
    ) {}

    public function aclarar(RelacionDistribuidora $relation, array $data, User $actor): AclaracionPago
    {
        if (! $actor->hasPermissionTo('payment_clarifications.create_own') || $actor->distribuidora?->id !== $relation->distributor_id) {
            throw new ExcepcionConciliacion('PAYMENT_CLARIFICATION_SCOPE_DENIED', 'La relación no pertenece a la distribuidora autenticada.', 403);
        }

        return DB::transaction(function () use ($relation, $data, $actor): AclaracionPago {
            $relation = RelacionDistribuidora::query()->whereKey($relation->id)->lockForUpdate()->firstOrFail();
            if (AclaracionPago::query()->where('relation_id', $relation->id)->whereIn('status', ['OPEN', 'IN_REVIEW'])->exists()) {
                throw new ExcepcionConciliacion('PAYMENT_CLARIFICATION_ALREADY_OPEN', 'La relación ya tiene una aclaración activa.', 409);
            }

            $evidence = MediaFile::query()->with('bindings')->find($data['evidence_media_id']);
            $validEvidence = $evidence !== null
                && $evidence->uploaded_by === $actor->id
                && $evidence->validation_status === 'VALIDATED'
                && $evidence->file_type === 'CLARIFICATION'
                && $evidence->bindings->contains(fn ($binding): bool => $binding->owner_type === 'distributor_relation' && $binding->owner_id === $relation->id);
            if (! $validEvidence) {
                throw new ExcepcionConciliacion('PAYMENT_CLARIFICATION_EVIDENCE_INVALID', 'El comprobante no pertenece a esta relación o no está validado.', 422, [
                    'evidence_media_id' => ['Selecciona un comprobante válido de esta relación.'],
                ]);
            }

            $clarification = AclaracionPago::query()->create([
                'folio' => 'ACL-'.Str::upper(Str::random(12)),
                'distributor_id' => $relation->distributor_id,
                'relation_id' => $relation->id,
                'evidence_media_id' => $evidence->id,
                'created_by' => $actor->id,
                'reason' => $data['reason'],
                'status' => 'OPEN',
            ]);
            $relation->update(['review_status' => 'CLARIFICATION_OPEN']);
            $this->auditor->registrar('PAYMENT_CLARIFICATION_CREATED', 'payment_clarification', $clarification->id, $actor, $relation->branch_id, null, [
                'folio' => $clarification->folio,
                'relation_id' => $relation->id,
                'evidence_media_id' => $evidence->id,
            ], $data['reason']);

            return $clarification;
        }, 3);
    }

    public function solicitar(MovimientoBancario $movement, array $data, User $actor): SolicitudConciliacionManual
    {
        if (! $actor->hasRole('cashier') || ! $actor->hasPermissionTo('manual_reconciliation.request_branch')) {
            throw new ExcepcionConciliacion('MANUAL_RECONCILIATION_REQUEST_DENIED', 'Solo una cajera autorizada puede solicitar la conciliación manual.', 403);
        }

        return DB::transaction(function () use ($movement, $data, $actor): SolicitudConciliacionManual {
            $movement = MovimientoBancario::query()->with('import')->whereKey($movement->id)->lockForUpdate()->firstOrFail();
            if (! $actor->hasScopeForBranch($movement->import->branch_id)) {
                throw new ExcepcionConciliacion('MANUAL_RECONCILIATION_SCOPE_DENIED', 'El movimiento no pertenece al alcance de la cajera.', 403);
            }
            if ($movement->classification !== 'UNRECONCILED' || ! in_array($movement->reconciliation_status, ['UNRECONCILED'], true)) {
                throw new ExcepcionConciliacion('BANK_MOVEMENT_NOT_UNRECONCILED', 'El movimiento ya no está disponible para conciliación manual.', 409);
            }

            $relation = RelacionDistribuidora::query()->whereKey($data['relation_id'])->lockForUpdate()->firstOrFail();
            $clarification = AclaracionPago::query()->whereKey($data['clarification_id'])->lockForUpdate()->firstOrFail();
            if ($relation->branch_id !== $movement->import->branch_id || $clarification->relation_id !== $relation->id || $clarification->status !== 'OPEN') {
                throw new ExcepcionConciliacion('MANUAL_RECONCILIATION_LINK_INVALID', 'La relación y la aclaración no corresponden al movimiento y sucursal seleccionados.', 422);
            }
            if (bccomp($relation->balance, '0', 4) <= 0) {
                throw new ExcepcionConciliacion('RELATION_HAS_NO_PENDING_BALANCE', 'La relación ya no tiene saldo pendiente.', 409);
            }
            if (SolicitudConciliacionManual::query()->where('bank_movement_id', $movement->id)->whereIn('status', ['REQUESTED', 'AUTHORIZED', 'EXECUTED'])->exists()) {
                throw new ExcepcionConciliacion('MANUAL_RECONCILIATION_ALREADY_REQUESTED', 'El movimiento ya tiene una solicitud de conciliación manual.', 409);
            }

            $request = SolicitudConciliacionManual::query()->create([
                'bank_movement_id' => $movement->id,
                'relation_id' => $relation->id,
                'clarification_id' => $clarification->id,
                'branch_id' => $relation->branch_id,
                'reason' => $data['reason'],
                'status' => 'REQUESTED',
                'requested_by' => $actor->id,
            ]);
            $movement->update(['reconciliation_status' => 'MANUAL_REQUESTED']);
            $clarification->update(['status' => 'IN_REVIEW']);
            $relation->update(['review_status' => 'MANUAL_REVIEW']);
            $this->auditor->registrar('MANUAL_RECONCILIATION_REQUESTED', 'manual_reconciliation_request', $request->id, $actor, $relation->branch_id, null, [
                'bank_movement_id' => $movement->id,
                'relation_id' => $relation->id,
                'clarification_id' => $clarification->id,
            ], $data['reason']);

            return $request;
        }, 3);
    }

    public function decidir(SolicitudConciliacionManual $manualRequest, array $data, User $actor): SolicitudConciliacionManual
    {
        return DB::transaction(function () use ($manualRequest, $data, $actor): SolicitudConciliacionManual {
            $manualRequest = SolicitudConciliacionManual::query()->with('relation')->whereKey($manualRequest->id)->lockForUpdate()->firstOrFail();
            if ($manualRequest->requested_by === $actor->id) {
                $this->auditor->registrar('MANUAL_RECONCILIATION_SELF_AUTHORIZATION_DENIED', 'manual_reconciliation_request', $manualRequest->id, $actor, $manualRequest->branch_id, null, null, $data['reason'] ?? null, null, null, 'DENIED');
                throw new ExcepcionConciliacion('MANUAL_RECONCILIATION_SELF_AUTHORIZATION_DENIED', 'La cajera solicitante no puede autorizar su propia conciliación.', 403);
            }
            if (! $this->puedeAutorizar($manualRequest, $actor)) {
                throw new ExcepcionConciliacion('MANUAL_RECONCILIATION_AUTHORIZATION_DENIED', 'El usuario no puede autorizar esta conciliación manual.', 403);
            }
            if ($manualRequest->status !== 'REQUESTED') {
                throw new ExcepcionConciliacion('MANUAL_RECONCILIATION_NOT_PENDING', 'La solicitud ya fue decidida.', 409);
            }

            $authorized = $data['decision'] === 'AUTHORIZE';
            $manualRequest->update([
                'status' => $authorized ? 'AUTHORIZED' : 'REJECTED',
                'authorized_by' => $actor->id,
                'decision_reason' => $data['reason'] ?? null,
                'decided_at' => now(),
                'authorized_at' => $authorized ? now() : null,
            ]);
            $manualRequest->movement()->update(['reconciliation_status' => $authorized ? 'MANUAL_AUTHORIZED' : 'UNRECONCILED']);
            if (! $authorized) {
                $manualRequest->clarification()->update(['status' => 'REJECTED']);
                $manualRequest->relation()->update(['review_status' => 'RESOLVED']);
            }
            $this->auditor->registrar(
                $authorized ? 'MANUAL_RECONCILIATION_AUTHORIZED' : 'MANUAL_RECONCILIATION_REJECTED',
                'manual_reconciliation_request',
                $manualRequest->id,
                $actor,
                $manualRequest->branch_id,
                ['status' => 'REQUESTED'],
                ['status' => $manualRequest->status],
                $data['reason'] ?? null,
                $actor->id
            );

            return $manualRequest->fresh();
        }, 3);
    }

    public function ejecutar(SolicitudConciliacionManual $manualRequest, User $actor): SolicitudConciliacionManual
    {
        if (! $actor->hasRole('cashier') || ! $actor->hasPermissionTo('manual_reconciliation.execute_branch')) {
            throw new ExcepcionConciliacion('MANUAL_RECONCILIATION_EXECUTION_DENIED', 'Solo una cajera autorizada puede ejecutar la conciliación.', 403);
        }

        return DB::transaction(function () use ($manualRequest, $actor): SolicitudConciliacionManual {
            $manualRequest = SolicitudConciliacionManual::query()->whereKey($manualRequest->id)->lockForUpdate()->firstOrFail();
            if (! $actor->hasScopeForBranch($manualRequest->branch_id)) {
                throw new ExcepcionConciliacion('MANUAL_RECONCILIATION_SCOPE_DENIED', 'La solicitud no pertenece al alcance de la cajera.', 403);
            }
            if ($manualRequest->status !== 'AUTHORIZED') {
                throw new ExcepcionConciliacion('MANUAL_RECONCILIATION_NOT_AUTHORIZED', 'La solicitud requiere autorización antes de ejecutarse.', 409);
            }

            $relation = RelacionDistribuidora::query()->whereKey($manualRequest->relation_id)->lockForUpdate()->firstOrFail();
            $movement = MovimientoBancario::query()->whereKey($manualRequest->bank_movement_id)->lockForUpdate()->firstOrFail();
            if ($movement->reconciliation_status !== 'MANUAL_AUTHORIZED') {
                throw new ExcepcionConciliacion('BANK_MOVEMENT_ALREADY_RECONCILED', 'El movimiento ya no está disponible para aplicación.', 409);
            }

            $before = ['balance' => $relation->balance, 'reconciled_total' => $relation->reconciled_total];
            try {
                $this->payments->aplicar($movement, $relation);
            } catch (Throwable $exception) {
                if ($exception->getMessage() === 'PAYMENT_ALREADY_ALLOCATED') {
                    throw new ExcepcionConciliacion('BANK_MOVEMENT_ALREADY_RECONCILED', 'El movimiento ya fue aplicado al flujo de pagos.', 409);
                }
                throw $exception;
            }

            $relation->refresh();
            $movement->refresh()->update([
                'distributor_id' => $relation->distributor_id,
                'balance_before' => $before['balance'],
                'reconciliation_status' => 'MANUALLY_RECONCILED',
                'reconciled_by' => $actor->id,
                'reconciled_at' => now(),
            ]);
            $manualRequest->update([
                'status' => 'EXECUTED',
                'executed_by' => $actor->id,
                'executed_at' => now(),
                'before_snapshot' => $before,
                'after_snapshot' => ['balance' => $relation->balance, 'reconciled_total' => $relation->reconciled_total],
            ]);
            $manualRequest->clarification()->update(['status' => 'RESOLVED']);
            $relation->update(['review_status' => 'RESOLVED']);
            $this->auditor->registrar(
                'MANUAL_RECONCILIATION_EXECUTED',
                'manual_reconciliation_request',
                $manualRequest->id,
                $actor,
                $manualRequest->branch_id,
                $before,
                $manualRequest->after_snapshot,
                $manualRequest->reason,
                $manualRequest->authorized_by,
                $actor->id
            );

            return $manualRequest->fresh();
        }, 3);
    }

    private function puedeAutorizar(SolicitudConciliacionManual $manualRequest, User $actor): bool
    {
        if ($actor->hasRole('general_manager') && $actor->hasPermissionTo('manual_reconciliation.authorize_global')) {
            return true;
        }
        if ($actor->hasRole('branch_manager') && $actor->hasPermissionTo('manual_reconciliation.authorize_branch') && $actor->hasScopeForBranch($manualRequest->branch_id)) {
            return true;
        }
        if (! $actor->hasRole('coordinator') || ! $actor->hasPermissionTo('manual_reconciliation.authorize_branch') || ! $actor->hasScopeForBranch($manualRequest->branch_id)) {
            return false;
        }

        return CoordinatorDistributorAssignment::query()
            ->where('coordinator_id', $actor->id)
            ->where('distributor_id', $manualRequest->relation->distributor_id)
            ->where('branch_id', $manualRequest->branch_id)
            ->where('status', 'ACTIVE')
            ->whereNull('valid_to')
            ->exists();
    }
}
