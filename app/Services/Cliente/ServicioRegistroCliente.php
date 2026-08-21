<?php

namespace App\Services\Cliente;

use App\Enums\EstadoDistribuidora;
use App\Exceptions\ExcepcionCliente;
use App\Models\AsignacionClienteDistribuidora;
use App\Models\Cliente;
use App\Models\CuentaBancariaCliente;
use App\Models\Distribuidora;
use App\Models\DomicilioCliente;
use App\Models\OutboxEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ServicioRegistroCliente
{
    public function __construct(
        private readonly NormalizadorCurp $normalizadorCurp,
        private readonly NormalizadorDomicilio $normalizadorDomicilio,
        private readonly GeneradorHuellaDomicilio $generadorHuella,
        private readonly GeneradorNumeroCliente $generadorNumero,
        private readonly ProtectorDatosCliente $protector,
        private readonly AuditorCliente $auditor,
    ) {}

    public function registrar(array $datos, User $actor): Cliente
    {
        $distribuidora = $this->resolverDistribuidora($actor);

        try {
            return DB::transaction(function () use ($datos, $actor, $distribuidora): Cliente {
                $distribuidora = Distribuidora::query()->lockForUpdate()->find($distribuidora->id);
                if ($distribuidora === null || $distribuidora->status !== EstadoDistribuidora::ACTIVA) {
                    throw new ExcepcionCliente('CLIENT_ASSIGNMENT_NOT_ACTIVE', 'La distribuidora no tiene una asignación activa.', 409);
                }

                $curp = $this->normalizadorCurp->normalizar($datos['curp']);
                $curpHmac = $this->protector->hmacCurp($curp);
                $domicilioNormalizado = $this->normalizadorDomicilio->normalizar($datos['address']);
                $huellaDomicilio = $this->generadorHuella->generar($domicilioNormalizado);

                if (Cliente::query()->where('curp_hmac', $curpHmac)->exists()) {
                    throw new ExcepcionCliente(
                        'CLIENT_CURP_EXISTS',
                        'La CURP ya se encuentra registrada.',
                        409,
                        ['curp' => ['La CURP ya se encuentra registrada.']],
                    );
                }

                if (DomicilioCliente::query()
                    ->where('normalized_fingerprint_hmac', $huellaDomicilio)
                    ->where('is_current', true)
                    ->whereNull('ends_at')
                    ->exists()) {
                    throw new ExcepcionCliente(
                        'CLIENT_ADDRESS_EXISTS',
                        'El domicilio ya se encuentra registrado.',
                        409,
                        ['address' => ['El domicilio ya se encuentra registrado.']],
                    );
                }

                $cliente = new Cliente([
                    'client_number' => $this->generadorNumero->generar(),
                    'first_name' => trim($datos['first_name']),
                    'first_last_name' => trim($datos['first_last_name']),
                    'second_last_name' => $this->nuloSiVacio($datos['second_last_name'] ?? null),
                    'birth_date' => $datos['birth_date'],
                    'birth_place' => trim($datos['birth_place']),
                    'birth_state' => trim($datos['birth_state']),
                    'birth_city' => trim($datos['birth_city']),
                    'official_id_type' => $datos['official_id_type'],
                    'official_id_media_id' => $datos['official_id_media_id'] ?? null,
                    'created_by' => $actor->id,
                ]);

                $rfc = $this->normalizarIdentificador($datos['rfc'] ?? null);
                $identificacion = $this->normalizarIdentificador($datos['official_id_number'] ?? null);
                $cliente->forceFill([
                    'curp_ciphertext' => $this->protector->cifrar($curp),
                    'curp_hmac' => $curpHmac,
                    'rfc_ciphertext' => $rfc === null ? null : $this->protector->cifrar($rfc),
                    'rfc_hmac' => $rfc === null ? null : $this->protector->hmacExacto($rfc),
                    'official_id_number_ciphertext' => $identificacion === null ? null : $this->protector->cifrar($identificacion),
                    'official_id_number_hmac' => $identificacion === null ? null : $this->protector->hmacExacto($identificacion),
                    'lock_version' => 1,
                ])->save();

                $ahora = now();
                $domicilio = new DomicilioCliente([
                    'client_id' => $cliente->id,
                    'is_current' => true,
                    'street' => trim($datos['address']['street']),
                    'exterior_number' => trim($datos['address']['exterior_number']),
                    'interior_number' => $this->nuloSiVacio($datos['address']['interior_number'] ?? null),
                    'neighborhood' => trim($datos['address']['neighborhood']),
                    'postal_code' => trim($datos['address']['postal_code']),
                    'municipality' => trim($datos['address']['municipality']),
                    'city' => trim($datos['address']['city']),
                    'state' => trim($datos['address']['state']),
                    'country' => $domicilioNormalizado['country'],
                    'address_proof_media_id' => $datos['address']['address_proof_media_id'] ?? null,
                    'starts_at' => $ahora,
                    'created_by' => $actor->id,
                ]);
                $domicilio->forceFill(['normalized_fingerprint_hmac' => $huellaDomicilio])->save();

                $cuenta = new CuentaBancariaCliente([
                    'client_id' => $cliente->id,
                    'bank_name' => trim($datos['bank_account']['bank_name']),
                    'account_holder_name' => trim($datos['bank_account']['account_holder_name']),
                    'is_current' => true,
                    'starts_at' => $ahora,
                    'created_by' => $actor->id,
                ]);
                $numeroCuenta = $this->nuloSiVacio($datos['bank_account']['account_number'] ?? null);
                $clabe = trim($datos['bank_account']['clabe']);
                $cuenta->forceFill([
                    'account_number_ciphertext' => $numeroCuenta === null ? null : $this->protector->cifrar($numeroCuenta),
                    'account_number_hmac' => $numeroCuenta === null ? null : $this->protector->hmacExacto($numeroCuenta),
                    'clabe_ciphertext' => $this->protector->cifrar($clabe),
                    'clabe_hmac' => $this->protector->hmacExacto($clabe),
                    'lock_version' => 1,
                ])->save();

                AsignacionClienteDistribuidora::create([
                    'client_id' => $cliente->id,
                    'distributor_id' => $distribuidora->id,
                    'branch_id' => $distribuidora->branch_id,
                    'starts_at' => $ahora,
                    'assigned_by' => $actor->id,
                    'reason' => 'Registro inicial del cliente',
                ]);

                OutboxEvent::create([
                    'event_type' => 'CLIENT_CREATED',
                    'payload' => [
                        'client_id' => $cliente->id,
                        'client_number' => $cliente->client_number,
                        'distributor_id' => $distribuidora->id,
                        'branch_id' => $distribuidora->branch_id,
                    ],
                    'status' => 'PENDING',
                ]);

                $this->auditor->registrar(
                    'CLIENT_CREATED',
                    $cliente->id,
                    $actor,
                    $distribuidora->branch_id,
                    $distribuidora->id,
                    nuevos: ['client_number' => $cliente->client_number],
                );

                return $cliente->load(['domicilioVigente', 'cuentaBancariaVigente', 'asignacionVigente.distribuidora', 'asignacionVigente.sucursal']);
            }, 3);
        } catch (ExcepcionCliente $excepcion) {
            $this->auditarRechazo($excepcion, $actor, $distribuidora);
            throw $excepcion;
        } catch (QueryException $excepcion) {
            $traducida = $this->traducirConflicto($excepcion);
            $this->auditarRechazo($traducida, $actor, $distribuidora);
            throw $traducida;
        }
    }

    /** @param array{first_name: string, first_last_name: string, second_last_name?: string|null} $datos */
    public function registrarBasicoParaVale(array $datos, User $actor): Cliente
    {
        $distribuidora = $this->resolverDistribuidora($actor);

        return DB::transaction(function () use ($datos, $actor, $distribuidora): Cliente {
            $distribuidora = Distribuidora::query()->lockForUpdate()->find($distribuidora->id);
            if ($distribuidora === null || $distribuidora->status !== EstadoDistribuidora::ACTIVA) {
                throw new ExcepcionCliente('CLIENT_ASSIGNMENT_NOT_ACTIVE', 'La distribuidora no tiene una asignación activa.', 409);
            }

            $cliente = Cliente::query()->create([
                'client_number' => $this->generadorNumero->generar(),
                'first_name' => trim($datos['first_name']),
                'first_last_name' => trim($datos['first_last_name']),
                'second_last_name' => $this->nuloSiVacio($datos['second_last_name'] ?? null),
                'created_by' => $actor->id,
                'lock_version' => 1,
            ]);
            $ahora = now();

            AsignacionClienteDistribuidora::query()->create([
                'client_id' => $cliente->id,
                'distributor_id' => $distribuidora->id,
                'branch_id' => $distribuidora->branch_id,
                'starts_at' => $ahora,
                'assigned_by' => $actor->id,
                'reason' => 'Registro básico para otorgamiento de vale',
            ]);
            OutboxEvent::query()->create([
                'event_type' => 'CLIENT_CREATED',
                'payload' => ['client_id' => $cliente->id, 'client_number' => $cliente->client_number, 'distributor_id' => $distribuidora->id, 'branch_id' => $distribuidora->branch_id],
                'status' => 'PENDING',
            ]);
            $this->auditor->registrar('CLIENT_CREATED', $cliente->id, $actor, $distribuidora->branch_id, $distribuidora->id, nuevos: ['client_number' => $cliente->client_number]);

            return $cliente->load(['asignacionVigente.distribuidora', 'asignacionVigente.sucursal']);
        }, 3);
    }

    private function resolverDistribuidora(User $actor): Distribuidora
    {
        $scopeIds = $actor->roleScopes()
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->where('scope_type', 'DISTRIBUTOR')
            ->whereNotNull('scope_id')
            ->pluck('scope_id');

        $distribuidora = Distribuidora::query()
            ->where('user_id', $actor->id)
            ->whereIn('id', $scopeIds)
            ->first();

        if ($distribuidora === null) {
            throw new ExcepcionCliente('AUTH_SCOPE_DENIED', 'No existe una distribuidora efectiva para la sesión.', 403);
        }

        return $distribuidora;
    }

    private function traducirConflicto(QueryException $excepcion): ExcepcionCliente
    {
        $detalle = mb_strtolower((string) ($excepcion->errorInfo[2] ?? $excepcion->getMessage()));

        return match (true) {
            str_contains($detalle, 'clients_curp_hmac_unique') => new ExcepcionCliente('CLIENT_CURP_EXISTS', 'La CURP ya se encuentra registrada.', 409, ['curp' => ['La CURP ya se encuentra registrada.']]),
            str_contains($detalle, 'client_addresses_current_fingerprint_unique') => new ExcepcionCliente('CLIENT_ADDRESS_EXISTS', 'El domicilio ya se encuentra registrado.', 409, ['address' => ['El domicilio ya se encuentra registrado.']]),
            str_contains($detalle, 'client_bank_accounts_current_client_unique') => new ExcepcionCliente('CLIENT_BANK_ACCOUNT_CONFLICT', 'No fue posible registrar la cuenta bancaria.', 409),
            default => new ExcepcionCliente('CLIENT_REGISTRATION_CONFLICT', 'No fue posible completar el registro del cliente.', 409),
        };
    }

    private function auditarRechazo(ExcepcionCliente $excepcion, User $actor, Distribuidora $distribuidora): void
    {
        $evento = match ($excepcion->codigo) {
            'CLIENT_CURP_EXISTS' => 'CLIENT_DUPLICATE_CURP_REJECTED',
            'CLIENT_ADDRESS_EXISTS' => 'CLIENT_DUPLICATE_ADDRESS_REJECTED',
            default => 'CLIENT_REGISTRATION_REJECTED',
        };

        $this->auditor->registrar($evento, null, $actor, $distribuidora->branch_id, $distribuidora->id, 'REJECTED', $excepcion->codigo);
    }

    private function normalizarIdentificador(?string $valor): ?string
    {
        $valor = $this->nuloSiVacio($valor);

        return $valor === null ? null : mb_strtoupper((string) preg_replace('/\s+/', '', $valor));
    }

    private function nuloSiVacio(?string $valor): ?string
    {
        $valor = $valor === null ? null : trim($valor);

        return $valor === '' ? null : $valor;
    }
}
