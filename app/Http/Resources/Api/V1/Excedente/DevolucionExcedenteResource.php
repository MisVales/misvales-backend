<?php

namespace App\Http\Resources\Api\V1\Excedente;

use App\Services\Cliente\ProtectorDatosCliente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DevolucionExcedenteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $account = $this->surplus?->distributor?->cuentaBancariaVigente;
        $canExecute = $this->status === 'AUTHORIZED'
            && $request->user()?->hasRole('cashier')
            && $request->user()?->hasPermissionTo('refunds.execute_branch')
            && $request->user()?->hasScopeForBranch($this->branch_id);

        return [
            'id' => $this->id,
            'surplus_id' => $this->surplus_id,
            'branch_id' => $this->branch_id,
            'branch_name' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'amount' => $this->amount,
            'status' => $this->status,
            'requested_by' => $this->requested_by,
            'requester_name' => $this->whenLoaded('requester', fn () => $this->requester?->name),
            'decided_by' => $this->decided_by,
            'decision_maker_name' => $this->whenLoaded('decisionMaker', fn () => $this->decisionMaker?->name),
            'decision_reason' => $this->decision_reason,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'authorized_at' => $this->authorized_at?->toIso8601String(),
            'executed_by' => $this->executed_by,
            'executor_name' => $this->whenLoaded('executor', fn () => $this->executor?->name),
            'execution_method' => $this->execution_method,
            'execution_reference' => $this->execution_reference,
            'execution_amount' => $this->execution_amount,
            'execution_observations' => $this->execution_observations,
            'evidence_media_id' => $this->evidence_media_id,
            'executed_at' => $this->executed_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'distributor_id' => $this->whenLoaded('surplus', fn () => $this->surplus?->distributor_id),
            'distributor_name' => $this->whenLoaded('surplus', fn () => $this->surplus?->distributor?->usuario?->name),
            'origin_relation_id' => $this->whenLoaded('surplus', fn () => $this->surplus?->origin_relation_id),
            'origin_relation_reference' => $this->whenLoaded('surplus', fn () => $this->surplus?->originRelation?->payment_reference),
            'bank_movement_id' => $this->whenLoaded('surplus', fn () => $this->surplus?->bank_movement_id),
            'bank_folio' => $this->whenLoaded('surplus', fn () => $this->surplus?->bankMovement?->bank_folio),
            'destination_bank_account' => $this->when(
                $canExecute && $account?->clabe_ciphertext !== null,
                fn (): array => [
                    'bank_name' => $account->bank_name,
                    'account_holder_name' => $account->account_holder_name,
                    'clabe' => app(ProtectorDatosCliente::class)->descifrar($account->clabe_ciphertext),
                ],
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
