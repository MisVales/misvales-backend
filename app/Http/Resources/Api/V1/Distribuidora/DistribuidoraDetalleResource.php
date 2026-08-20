<?php

namespace App\Http\Resources\Api\V1\Distribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DistribuidoraDetalleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $categoria = $this->categoriaVigente?->versionCategoria;
        $coordinador = $this->coordinadorVigente?->coordinator;
        $restriccion = $this->lineaCredito?->restricciones->firstWhere('type', 'INITIAL_50_PERCENT');
        $puedeVerCreditoInicial = $this->lineaCredito !== null
            && ($request->user()?->can('view', $this->lineaCredito) ?? false);

        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'user_id' => $this->user_id,
            'distributor_number' => $this->distributor_number,
            'full_name' => $this->usuario?->name,
            'status' => $this->status->value,
            'activation_status' => $this->usuario?->state,
            'branch' => $this->sucursal ? ['id' => $this->sucursal->id, 'name' => $this->sucursal->name] : null,
            'coordinator' => $coordinador ? ['id' => $coordinador->id, 'name' => $coordinador->name] : null,
            'category' => $categoria ? [
                'id' => $categoria->category_id,
                'version_id' => $categoria->id,
                'name' => $categoria->name,
                'profit_rate' => $categoria->profit_percentage,
            ] : null,
            'category_history' => AsignacionCategoriaResource::collection($this->whenLoaded('asignacionesCategoria')),
            'origin' => $this->solicitud ? [
                'application_id' => $this->solicitud->id,
                'application_status' => $this->solicitud->status->value,
                'authorization' => $this->solicitud->autorizacion ? [
                    'id' => $this->solicitud->autorizacion->id,
                    'decision' => $this->solicitud->autorizacion->decision->value,
                    'authorized_at' => $this->solicitud->autorizacion->authorized_at?->toIso8601String(),
                ] : null,
            ] : null,
            'initial_credit' => $this->when($puedeVerCreditoInicial, fn () => [
                'total_authorized' => $this->lineaCredito->total_authorized,
                'used_balance' => $this->lineaCredito->used_balance,
                'available_balance' => $this->lineaCredito->saldoDisponible(),
            ]),
            'initial_restriction' => $this->when($puedeVerCreditoInicial, fn () => $restriccion ? [
                'type' => $restriccion->type,
                'status' => $restriccion->status->value,
                'base_total' => $restriccion->base_total,
                'consumed_at' => $restriccion->consumed_at?->toIso8601String(),
            ] : null),
            'lock_version' => $this->lock_version,
            'created_at' => $this->created_at?->toIso8601String(),
            'activated_at' => $this->activated_at?->toIso8601String(),
        ];
    }
}
