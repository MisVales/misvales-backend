<?php

namespace App\Http\Resources\Api\V1\Cliente;

use App\Services\Cliente\ProtectorDatosCliente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CuentaBancariaClienteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $protector = app(ProtectorDatosCliente::class);
        $clabe = $protector->descifrar($this->clabe_ciphertext);
        $numero = $this->account_number_ciphertext === null ? null : $protector->descifrar($this->account_number_ciphertext);

        return [
            'id' => $this->id,
            'bank_name' => $this->bank_name,
            'account_holder_name' => $this->account_holder_name,
            'account_number_masked' => $numero === null ? null : $protector->ultimosCuatro($numero),
            'clabe_masked' => $protector->ultimosCuatro($clabe),
            'is_current' => $this->is_current,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'change_reason' => $this->change_reason,
            'lock_version' => $this->lock_version,
        ];
    }
}
