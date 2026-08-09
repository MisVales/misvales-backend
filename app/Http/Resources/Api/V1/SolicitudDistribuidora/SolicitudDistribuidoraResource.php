<?php

namespace App\Http\Resources\Api\V1\SolicitudDistribuidora;

use App\Services\SolicitudDistribuidora\ProtectorDatosSolicitud;
use App\Services\SolicitudDistribuidora\ValidadorExpedienteSolicitud;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SolicitudDistribuidoraResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $avance = app(ValidadorExpedienteSolicitud::class)->calcularSeccionesCompletas($this->resource);
        $nombre = $this->datosPersonales === null
            ? null
            : trim(implode(' ', array_filter([
                $this->datosPersonales->first_name,
                $this->datosPersonales->first_last_name,
                $this->datosPersonales->second_last_name,
            ])));
        $curpEnmascarada = null;

        if ($this->datosPersonales?->curp_ciphertext !== null) {
            $protector = app(ProtectorDatosSolicitud::class);
            $curpEnmascarada = $protector->enmascarar($protector->descifrar($this->datosPersonales->curp_ciphertext));
        }

        return [
            'id' => $this->id,
            'application_number' => $this->application_number,
            'status' => $this->status->value,
            'branch' => [
                'id' => $this->branch_id,
                'name' => $this->whenLoaded('sucursal', fn () => $this->sucursal?->name),
            ],
            'coordinator' => [
                'id' => $this->coordinator_id,
                'name' => $this->whenLoaded('coordinador', fn () => $this->coordinador?->name),
            ],
            'applicant' => [
                'full_name' => $nombre,
                'curp_masked' => $curpEnmascarada,
            ],
            'completion' => $avance,
            'lock_version' => $this->lock_version,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
        ];
    }
}
