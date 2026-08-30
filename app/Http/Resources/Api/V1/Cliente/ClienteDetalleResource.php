<?php

namespace App\Http\Resources\Api\V1\Cliente;

use App\Services\Cliente\ProtectorDatosCliente;
use Illuminate\Http\Request;

class ClienteDetalleResource extends ClienteResource
{
    public function toArray(Request $request): array
    {
        $base = parent::toArray($request);
        $protector = app(ProtectorDatosCliente::class);
        $puedeVerSensible = $request->user()?->can('viewSensitive', $this->resource) === true;
        $rfc = $this->rfc_ciphertext === null ? null : $protector->descifrar($this->rfc_ciphertext);
        $identificacion = $this->official_id_number_ciphertext === null ? null : $protector->descifrar($this->official_id_number_ciphertext);

        return array_merge($base, [
            'first_name' => $this->first_name,
            'first_last_name' => $this->first_last_name,
            'second_last_name' => $this->second_last_name,
            'phone_number' => $this->phone_number,
            'curp' => $this->when($puedeVerSensible, fn () => $protector->descifrar($this->curp_ciphertext)),
            'rfc_masked' => $rfc === null ? null : $protector->enmascarar($rfc, 3, 3),
            'rfc' => $this->when($puedeVerSensible, $rfc),
            'birth_place' => $this->birth_place,
            'birth_state' => $this->birth_state,
            'birth_city' => $this->birth_city,
            'official_id_type' => $this->official_id_type,
            'official_id_number_masked' => $identificacion === null ? null : $protector->enmascarar($identificacion, 2, 2),
            'official_id_number' => $this->when($puedeVerSensible, $identificacion),
            'address_history' => $this->whenLoaded('domicilios', fn () => $this->domicilios->map(fn ($domicilio): array => [
                'id' => $domicilio->id,
                'is_current' => $domicilio->is_current,
                'street' => $domicilio->street,
                'exterior_number' => $domicilio->exterior_number,
                'interior_number' => $domicilio->interior_number,
                'neighborhood' => $domicilio->neighborhood,
                'postal_code' => $domicilio->postal_code,
                'municipality' => $domicilio->municipality,
                'city' => $domicilio->city,
                'state' => $domicilio->state,
                'country' => $domicilio->country,
                'starts_at' => $domicilio->starts_at?->toIso8601String(),
                'ends_at' => $domicilio->ends_at?->toIso8601String(),
            ])),
            'bank_account_history' => $this->whenLoaded('cuentasBancarias', fn () => $this->cuentasBancarias->map(function ($cuenta) use ($protector): array {
                $clabe = $protector->descifrar($cuenta->clabe_ciphertext);

                return [
                    'id' => $cuenta->id,
                    'bank_name' => $cuenta->bank_name,
                    'account_holder_name' => $cuenta->account_holder_name,
                    'clabe_masked' => $protector->ultimosCuatro($clabe),
                    'is_current' => $cuenta->is_current,
                    'starts_at' => $cuenta->starts_at?->toIso8601String(),
                    'ends_at' => $cuenta->ends_at?->toIso8601String(),
                    'change_reason' => $cuenta->change_reason,
                ];
            })),
            'assignment_history' => $this->whenLoaded('asignacionesDistribuidora', fn () => $this->asignacionesDistribuidora->map(fn ($asignacion): array => [
                'id' => $asignacion->id,
                'distributor_id' => $asignacion->distributor_id,
                'branch_id' => $asignacion->branch_id,
                'starts_at' => $asignacion->starts_at?->toIso8601String(),
                'ends_at' => $asignacion->ends_at?->toIso8601String(),
                'reason' => $asignacion->reason,
            ])),
        ]);
    }
}
