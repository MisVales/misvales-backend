<?php

namespace App\Http\Resources\Api\V1\Vale;

use App\Models\SolicitudModificacionVale;
use App\Services\Cliente\ProtectorDatosCliente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ValeCajaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $protector = app(ProtectorDatosCliente::class);
        $cliente = $this->cliente;
        $domicilio = $cliente?->domicilioVigente;
        $archivos = $cliente?->archivosAdjuntos ?? collect();
        $ine = $cliente?->official_id_media_id ?? $archivos->firstWhere('purpose', 'CLIENT_INE_FRONT')?->media_file_id;
        $comprobante = $domicilio?->address_proof_media_id ?? $archivos->firstWhere('purpose', 'ADDRESS_PROOF')?->media_file_id;
        $cuenta = $cliente?->cuentaBancariaVigente;
        $clabe = $cuenta?->clabe_ciphertext === null ? null : $protector->descifrar($cuenta->clabe_ciphertext);
        $solicitudModificacion = $this->resource->relationLoaded('solicitudesModificacion')
            ? $this->resource->solicitudesModificacion->first()
            : null;
        $solicitudPropia = $solicitudModificacion instanceof SolicitudModificacionVale
            && $solicitudModificacion->requested_by === $request->user()?->id
            && in_array($solicitudModificacion->status, ['REQUESTED', 'AUTHORIZED'], true)
                ? ['id' => $solicitudModificacion->id, 'lock_version' => $solicitudModificacion->lock_version, 'status' => $solicitudModificacion->status]
                : null;

        return [
            ...(new ValeResource($this->resource))->toArray($request),
            'client_verification' => $cliente === null ? null : [
                'id' => $cliente->id,
                'first_name' => $cliente->first_name,
                'first_last_name' => $cliente->first_last_name,
                'second_last_name' => $cliente->second_last_name,
                'full_name' => trim(implode(' ', array_filter([$cliente->first_name, $cliente->first_last_name, $cliente->second_last_name]))),
                'birth_date' => $cliente->birth_date?->format('Y-m-d'),
                'phone_number' => $cliente->phone_number,
                'identity' => [
                    'official_id_type' => $cliente->official_id_type,
                    'official_id_media_id' => $ine,
                ],
                'address' => $domicilio === null ? null : [
                    'street' => $domicilio->street,
                    'exterior_number' => $domicilio->exterior_number,
                    'interior_number' => $domicilio->interior_number,
                    'neighborhood' => $domicilio->neighborhood,
                    'postal_code' => $domicilio->postal_code,
                    'municipality' => $domicilio->municipality,
                    'city' => $domicilio->city,
                    'state' => $domicilio->state,
                    'country' => $domicilio->country,
                    'address_proof_media_id' => $comprobante,
                ],
            ],
            'identity' => ['official_id_type' => $cliente?->official_id_type, 'official_id_number' => null, 'official_id_number_masked' => null, 'official_id_media_id' => $ine],
            'address' => $domicilio === null ? null : [
                'street' => $domicilio->street,
                'exterior_number' => $domicilio->exterior_number,
                'interior_number' => $domicilio->interior_number,
                'neighborhood' => $domicilio->neighborhood,
                'postal_code' => $domicilio->postal_code,
                'municipality' => $domicilio->municipality,
                'city' => $domicilio->city,
                'state' => $domicilio->state,
                'country' => $domicilio->country,
                'address_proof_media_id' => $comprobante,
            ],
            'bank_account' => $cuenta === null ? null : ['bank_name' => $cuenta->bank_name, 'account_holder_name' => $cuenta->account_holder_name, 'clabe_masked' => $clabe === null ? null : $protector->ultimosCuatro($clabe)],
            'modification_request' => $solicitudPropia,
            'released_at' => $this->released_at?->toIso8601String(),
            'cashed_at' => $this->cashed_at?->toIso8601String(),
        ];
    }
}
