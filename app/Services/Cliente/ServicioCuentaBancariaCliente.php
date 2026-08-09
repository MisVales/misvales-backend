<?php

namespace App\Services\Cliente;

use App\Exceptions\ExcepcionCliente;
use App\Models\Cliente;
use App\Models\CuentaBancariaCliente;
use App\Models\OutboxEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ServicioCuentaBancariaCliente
{
    public function __construct(
        private readonly ProtectorDatosCliente $protector,
        private readonly AuditorCliente $auditor,
    ) {}

    public function registrar(Cliente $cliente, array $datos, User $actor): CuentaBancariaCliente
    {
        return DB::transaction(function () use ($cliente, $datos, $actor): CuentaBancariaCliente {
            $cliente = Cliente::query()->lockForUpdate()->findOrFail($cliente->id);
            if ($cliente->lock_version !== (int) $datos['lock_version']) {
                throw new ExcepcionCliente('RESOURCE_VERSION_CONFLICT', 'El cliente fue modificado por otra operación.', 409);
            }

            $asignacion = $cliente->asignacionVigente()->with('distribuidora')->lockForUpdate()->first();
            if ($asignacion === null || $asignacion->distribuidora?->user_id !== $actor->id) {
                throw new ExcepcionCliente('CLIENT_SCOPE_DENIED', 'El cliente no está dentro del alcance autorizado.', 403);
            }

            $vigente = $cliente->cuentaBancariaVigente()->lockForUpdate()->first();
            $cuentaAnteriorEnmascarada = null;
            $ahora = now();
            if ($vigente !== null) {
                $finMinimo = $vigente->starts_at->addSecond();
                if ($ahora->lessThan($finMinimo)) {
                    $ahora = $finMinimo;
                }
                $cuentaAnteriorEnmascarada = mb_substr($this->protector->descifrar($vigente->clabe_ciphertext), -4);
                $vigente->forceFill(['is_current' => false, 'ends_at' => $ahora])->save();
            }

            $numero = isset($datos['account_number']) && trim((string) $datos['account_number']) !== '' ? trim($datos['account_number']) : null;
            $clabe = trim($datos['clabe']);
            $cuenta = new CuentaBancariaCliente([
                'client_id' => $cliente->id,
                'bank_name' => trim($datos['bank_name']),
                'account_holder_name' => trim($datos['account_holder_name']),
                'is_current' => true,
                'starts_at' => $ahora,
                'created_by' => $actor->id,
                'change_reason' => trim($datos['change_reason']),
            ]);
            $cuenta->forceFill([
                'account_number_ciphertext' => $numero === null ? null : $this->protector->cifrar($numero),
                'account_number_hmac' => $numero === null ? null : $this->protector->hmacExacto($numero),
                'clabe_ciphertext' => $this->protector->cifrar($clabe),
                'clabe_hmac' => $this->protector->hmacExacto($clabe),
                'lock_version' => 1,
            ])->save();

            $cliente->forceFill(['lock_version' => $cliente->lock_version + 1])->save();
            OutboxEvent::create([
                'event_type' => 'CLIENT_BANK_ACCOUNT_ADDED',
                'payload' => ['client_id' => $cliente->id, 'bank_account_id' => $cuenta->id, 'distributor_id' => $asignacion->distributor_id],
                'status' => 'PENDING',
            ]);
            $this->auditor->registrar(
                'CLIENT_BANK_ACCOUNT_ADDED', $cliente->id, $actor, $asignacion->branch_id,
                $asignacion->distributor_id, motivo: $datos['change_reason'],
                nuevos: ['bank_name' => $cuenta->bank_name, 'masked_ending' => mb_substr($clabe, -4)],
                anteriores: $cuentaAnteriorEnmascarada === null ? [] : ['masked_ending' => $cuentaAnteriorEnmascarada],
            );

            return $cuenta;
        }, 3);
    }
}
