<?php

namespace App\Http\Resources\Api\V1\Vale;

use App\Services\Cliente\ProtectorDatosCliente;
use App\Services\SolicitudDistribuidora\ProtectorDatosSolicitud;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ValeCajaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $protector = app(ProtectorDatosCliente::class);
        $distribuidora = $this->distribuidora;
        $cuenta = $distribuidora?->cuentaBancariaVigente;
        $solicitud = $distribuidora?->solicitud;
        $datosPersonales = $solicitud?->datosPersonales;
        $domicilio = $solicitud?->domicilioActual;
        $archivos = $distribuidora?->archivosSolicitud ?? collect();
        $identificacionAdjunta = $archivos->firstWhere('purpose', 'IDENTIFICATION');
        $comprobanteAdjunto = $archivos->firstWhere('purpose', 'ADDRESS_PROOF');
        $protectorSolicitud = app(ProtectorDatosSolicitud::class);
        $numeroIdentificacion = $datosPersonales?->official_id_number_ciphertext === null
            ? null
            : $protectorSolicitud->descifrar($datosPersonales->official_id_number_ciphertext);

        return [...(new ValeResource($this->resource))->toArray($request), 'document_owner' => $solicitud === null ? null : ['owner_type' => 'distributor_application', 'owner_id' => $solicitud->id], 'identity' => ['official_id_type' => $datosPersonales?->official_id_type, 'official_id_number' => $numeroIdentificacion, 'official_id_number_masked' => $numeroIdentificacion === null ? null : $protectorSolicitud->enmascarar($numeroIdentificacion, 2, 2), 'official_id_media_id' => $identificacionAdjunta?->media_file_id], 'address' => ($domicilio || $comprobanteAdjunto) ? ['street' => $domicilio?->street, 'exterior_number' => $domicilio?->exterior_number, 'interior_number' => $domicilio?->interior_number, 'neighborhood' => $domicilio?->neighborhood, 'postal_code' => $domicilio?->postal_code, 'municipality' => $domicilio?->municipality, 'city' => $domicilio?->city, 'state' => $domicilio?->state, 'country' => $domicilio?->country, 'address_proof_media_id' => $comprobanteAdjunto?->media_file_id] : null, 'bank_account' => $cuenta?->clabe_ciphertext === null ? null : ['bank_name' => $cuenta->bank_name, 'account_holder_name' => $cuenta->account_holder_name, 'clabe_masked' => $protector->ultimosCuatro($protector->descifrar($cuenta->clabe_ciphertext))], 'released_at' => $this->released_at?->toIso8601String(), 'cashed_at' => $this->cashed_at?->toIso8601String()];
    }
}
