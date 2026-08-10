<?php

namespace App\Http\Resources\Api\V1\Credito;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\MovimientoLineaCredito;

class SolicitudIncrementoDetalleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        // Buscar movimientos relacionados a esta solicitud
        $movimientos = MovimientoLineaCredito::where('source_type', 'CREDIT_INCREASE_REQUEST')
            ->where('source_id', $this->id)
            ->get();

        return [
            'id' => $this->id,
            'request_number' => $this->request_number,
            'status' => $this->status,
            'distributor' => $this->whenLoaded('distribuidora', function () {
                return [
                    'id' => $this->distribuidora->id,
                    'distributor_number' => $this->distribuidora->distributor_number,
                    'full_name' => $this->distribuidora->usuario?->name,
                ];
            }),
            'branch' => $this->whenLoaded('sucursal', function () {
                return [
                    'id' => $this->sucursal->id,
                    'name' => $this->sucursal->name,
                ];
            }),
            'coordinator' => $this->whenLoaded('coordinadorSnapshot', function () {
                return [
                    'id' => $this->coordinadorSnapshot->id,
                    'name' => $this->coordinadorSnapshot->name,
                ];
            }),
            'requested_amount' => $this->requested_amount ? number_format((float) $this->requested_amount, 4, '.', '') : null,
            'recommended_amount' => $this->recommended_amount ? number_format((float) $this->recommended_amount, 4, '.', '') : null,
            'authorized_amount' => $this->authorized_amount ? number_format((float) $this->authorized_amount, 4, '.', '') : null,
            'line_total_at_request' => $this->line_total_at_request ? number_format((float) $this->line_total_at_request, 4, '.', '') : null,
            'used_balance_at_request' => $this->used_balance_at_request ? number_format((float) $this->used_balance_at_request, 4, '.', '') : null,
            'available_balance_at_request' => $this->available_balance_at_request ? number_format((float) $this->available_balance_at_request, 4, '.', '') : null,
            'request_reason' => $this->request_reason,
            'coordinator_reason' => $this->coordinator_reason,
            'manager_reason' => $this->manager_reason,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'coordinator_decided_at' => $this->coordinator_decided_at?->toIso8601String(),
            'manager_decided_at' => $this->manager_decided_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'lock_version' => $this->lock_version,
            'created_at' => $this->created_at->toIso8601String(),
            
            'current_credit_line' => $this->whenLoaded('lineaCredito', function () {
                $calculador = app(\App\Services\Credito\CalculadorSaldoCredito::class);
                $saldos = $calculador->calcular($this->lineaCredito->total_authorized, $this->lineaCredito->used_balance);
                return [
                    'id' => $this->lineaCredito->id,
                    'total_authorized' => $saldos['total_authorized'],
                    'used_balance' => $saldos['used_balance'],
                    'available_balance' => $saldos['available_balance'],
                ];
            }),
            
            'restriction' => $this->whenLoaded('restriccion', function () {
                return [
                    'id' => $this->restriccion->id,
                    'type' => $this->restriccion->type,
                    'status' => $this->restriccion->status,
                    'base_total' => number_format((float) $this->restriccion->base_total, 4, '.', ''),
                    'tolerance_amount' => number_format((float) $this->restriccion->tolerance_amount, 4, '.', ''),
                ];
            }),
            
            'movements' => $movimientos->map(function ($movimiento) {
                return [
                    'id' => $movimiento->id,
                    'sequence' => $movimiento->sequence,
                    'type' => $movimiento->type,
                    'amount' => number_format((float) $movimiento->amount, 4, '.', ''),
                    'total_authorized_before' => number_format((float) $movimiento->total_authorized_before, 4, '.', ''),
                    'total_authorized_after' => number_format((float) $movimiento->total_authorized_after, 4, '.', ''),
                    'used_balance_before' => number_format((float) $movimiento->used_balance_before, 4, '.', ''),
                    'used_balance_after' => number_format((float) $movimiento->used_balance_after, 4, '.', ''),
                    'occurred_at' => $movimiento->occurred_at->toIso8601String(),
                ];
            }),
            
            'state_history' => $this->whenLoaded('transiciones', function () {
                return $this->transiciones->map(function ($transicion) {
                    return [
                        'id' => $transicion->id,
                        'from_status' => $transicion->from_status,
                        'to_status' => $transicion->to_status,
                        'reason' => $transicion->reason,
                        'created_at' => $transicion->created_at->toIso8601String(),
                    ];
                });
            }),
        ];

        if ($request->user()) {
            $data['capabilities'] = [
                'can_preauthorize' => $request->user()->can('preauthorize', $this->resource) && $this->status === 'REQUESTED',
                'can_reject_by_coordinator' => $request->user()->can('rejectByCoordinator', $this->resource) && $this->status === 'REQUESTED',
                'can_decide' => $request->user()->can('managerDecision', $this->resource) && $this->status === 'PREAUTHORIZED',
            ];
        }

        return $data;
    }
}
