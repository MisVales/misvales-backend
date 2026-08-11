<?php

namespace App\Http\Resources\Api\V1\Distribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DistribuidoraResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $categoria = $this->categoriaVigente?->versionCategoria;
        $coordinador = $this->coordinadorVigente?->coordinator;
        $datos = $this->solicitud?->datosPersonales;

        return [
            'id' => $this->id,
            'distributor_number' => $this->distributor_number,
            'full_name' => trim(implode(' ', array_filter([
                $datos?->first_name,
                $datos?->first_last_name,
                $datos?->second_last_name,
            ]))),
            'branch' => $this->sucursal ? ['id' => $this->sucursal->id, 'name' => $this->sucursal->name] : null,
            'coordinator' => $coordinador ? ['id' => $coordinador->id, 'name' => $coordinador->name] : null,
            'category' => $categoria ? [
                'id' => $categoria->category_id,
                'name' => $categoria->name,
                'profit_rate' => $categoria->profit_percentage,
            ] : null,
            'status' => $this->status->value,
            'activation_status' => $this->usuario?->state,
            'lock_version' => $this->lock_version,
        ];
    }
}
