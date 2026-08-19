<?php

namespace App\Http\Resources\Api\V1\Vale;

use App\Services\Cliente\ProtectorDatosCliente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ValeCajaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $protector = app(ProtectorDatosCliente::class);
        $cliente = $this->cliente;
        $domicilio = $cliente->domicilioVigente;
        $cuenta = $cliente->cuentaBancariaVigente;
        $numeroIdentificacion = $cliente->official_id_number_ciphertext === null
            ? null
            : $protector->descifrar($cliente->official_id_number_ciphertext);

        return [...(new ValeResource($this->resource))->toArray($request), 'identity' => ['official_id_type' => $cliente->official_id_type, 'official_id_number' => $numeroIdentificacion, 'official_id_number_masked' => $numeroIdentificacion === null ? null : $protector->enmascarar($numeroIdentificacion, 2, 2), 'official_id_media_id' => $cliente->official_id_media_id], 'address' => $domicilio ? ['street' => $domicilio->street, 'exterior_number' => $domicilio->exterior_number, 'interior_number' => $domicilio->interior_number, 'neighborhood' => $domicilio->neighborhood, 'postal_code' => $domicilio->postal_code, 'municipality' => $domicilio->municipality, 'city' => $domicilio->city, 'state' => $domicilio->state, 'country' => $domicilio->country, 'address_proof_media_id' => $domicilio->address_proof_media_id] : null, 'bank_account' => $cuenta ? ['bank_name' => $cuenta->bank_name, 'account_holder_name' => $cuenta->account_holder_name, 'clabe_masked' => $protector->ultimosCuatro($protector->descifrar($cuenta->clabe_ciphertext))] : null, 'released_at' => $this->released_at?->toIso8601String(), 'cashed_at' => $this->cashed_at?->toIso8601String()];
    }
}
