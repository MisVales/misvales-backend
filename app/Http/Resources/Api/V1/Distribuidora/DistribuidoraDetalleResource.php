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
        $datos = $this->solicitud?->applicant_data ?? [];

        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'user_id' => $this->user_id,
            'distributor_number' => $this->distributor_number,
            'full_name' => trim(implode(' ', array_filter([
                data_get($datos, 'personal_info.first_name'),
                data_get($datos, 'personal_info.last_name'),
                data_get($datos, 'personal_info.second_last_name'),
            ]))),
            'status' => $this->status->value,
            'activation_status' => $this->usuario?->state,
            'access' => $this->usuario ? ['user_id' => $this->usuario->id, 'state' => $this->usuario->state] : null,
            'branch' => $this->sucursal ? ['id' => $this->sucursal->id, 'name' => $this->sucursal->name] : null,
            'coordinator' => $coordinador ? ['id' => $coordinador->id, 'name' => $coordinador->name] : null,
            'category' => $categoria ? [
                'id' => $categoria->category_id,
                'version_id' => $categoria->id,
                'name' => $categoria->name,
                'profit_rate' => $categoria->profit_percentage,
            ] : null,
            'category_history' => AsignacionCategoriaResource::collection($this->whenLoaded('asignacionesCategoria')),
            'coordinator_history' => AsignacionCoordinadorResource::collection($this->whenLoaded('asignacionesCoordinador')),
            'origin' => $this->solicitud ? [
                'application_id' => $this->solicitud->id,
                'application_status' => $this->solicitud->status->value,
                'authorization' => $this->solicitud->autorizacion ? [
                    'id' => $this->solicitud->autorizacion->id,
                    'decision' => $this->solicitud->autorizacion->decision->value === 'APPROVED' ? 'AUTORIZADA' : 'RECHAZADA',
                    'authorized_at' => $this->solicitud->autorizacion->authorized_at?->toIso8601String(),
                ] : null,
            ] : null,
            'lock_version' => $this->lock_version,
            'created_at' => $this->created_at?->toIso8601String(),
            'activated_at' => $this->activated_at?->toIso8601String(),
        ];
    }
}
