<?php

namespace App\Http\Resources\Api\V1\Cliente;

use App\Services\Cliente\ProtectorDatosCliente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClienteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $protector = app(ProtectorDatosCliente::class);
        $curp = $this->curp_ciphertext === null ? null : $protector->descifrar($this->curp_ciphertext);
        $cuenta = $this->cuentaBancariaVigente;
        $clabe = $cuenta?->clabe_ciphertext === null ? null : $protector->descifrar($cuenta->clabe_ciphertext);
        $incrementos = (string) ($this->portfolio_increases_sum_amount ?? '0.0000');
        $reducciones = (string) ($this->portfolio_reductions_sum_amount ?? '0.0000');
        $saldo = bccomp($incrementos, $reducciones, 4) > 0 ? bcsub($incrementos, $reducciones, 4) : '0.0000';
        $estadoCartera = match (true) {
            (int) ($this->movimientos_cartera_count ?? 0) === 0 => null,
            bccomp($saldo, '0.0000', 4) === 0 => 'PAID',
            bccomp($reducciones, '0.0000', 4) > 0 => 'PARTIALLY_PAID',
            default => 'PENDING',
        };

        return [
            'id' => $this->id,
            'client_number' => $this->client_number,
            'full_name' => trim(implode(' ', array_filter([$this->first_name, $this->first_last_name, $this->second_last_name]))),
            'curp_masked' => $curp === null ? null : $protector->enmascarar($curp, 4, 3),
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'address' => $this->whenLoaded('domicilioVigente', fn () => $this->domicilioVigente ? [
                'street' => $this->domicilioVigente->street,
                'exterior_number' => $this->domicilioVigente->exterior_number,
                'interior_number' => $this->domicilioVigente->interior_number,
                'neighborhood' => $this->domicilioVigente->neighborhood,
                'postal_code' => $this->domicilioVigente->postal_code,
                'municipality' => $this->domicilioVigente->municipality,
                'city' => $this->domicilioVigente->city,
                'state' => $this->domicilioVigente->state,
                'country' => $this->domicilioVigente->country,
            ] : null),
            'bank_account' => $this->whenLoaded('cuentaBancariaVigente', fn () => $cuenta ? [
                'bank_name' => $cuenta->bank_name,
                'account_holder_name' => $cuenta->account_holder_name,
                'clabe_masked' => $clabe === null ? null : $protector->ultimosCuatro($clabe),
            ] : null),
            'branch' => $this->whenLoaded('asignacionVigente', fn () => $this->asignacionVigente?->sucursal ? [
                'id' => $this->asignacionVigente->sucursal->id,
                'name' => $this->asignacionVigente->sucursal->name,
            ] : null),
            'distributor' => $this->whenLoaded('asignacionVigente', fn () => $this->asignacionVigente?->distribuidora ? [
                'id' => $this->asignacionVigente->distribuidora->id,
                'distributor_number' => $this->asignacionVigente->distribuidora->distributor_number,
            ] : null),
            'portfolio_summary' => [
                'current_balance' => $saldo,
                'informational_status' => $estadoCartera,
            ],
            'lock_version' => $this->lock_version,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
