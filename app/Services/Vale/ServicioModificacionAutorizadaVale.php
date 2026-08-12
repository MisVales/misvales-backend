<?php

namespace App\Services\Vale;

use App\Exceptions\ExcepcionVale;
use App\Helpers\AuditHelper;
use App\Models\Cliente;
use App\Models\DomicilioCliente;
use App\Models\SolicitudModificacionVale;
use App\Models\User;
use App\Models\Vale;
use App\Services\Cliente\GeneradorHuellaDomicilio;
use App\Services\Cliente\NormalizadorDomicilio;
use App\Services\Cliente\ProtectorDatosCliente;
use Illuminate\Support\Facades\DB;

final class ServicioModificacionAutorizadaVale
{
    private const CAMPOS = ['curp', 'address'];

    public function __construct(private readonly ProtectorDatosCliente $protector, private readonly NormalizadorDomicilio $normalizador, private readonly GeneradorHuellaDomicilio $huella) {}

    public function solicitar(Vale $vale, User $cajera, array $campos, string $motivo): SolicitudModificacionVale
    {
        $this->validarCajera($cajera, $vale);
        $campos = array_values(array_unique($campos));
        if ($campos === [] || array_diff($campos, self::CAMPOS) !== []) {
            throw new ExcepcionVale('MODIFICATION_FIELDS_INVALID', 'Solo pueden solicitarse campos corregibles autorizados.', 422);
        }
        if (! in_array($vale->status->value, ['GENERATED', 'CORRECTION_PENDING'], true)) {
            throw new ExcepcionVale('VOUCHER_STATUS_INVALID', 'El estado del vale no permite solicitar corrección.', 409);
        }

        return DB::transaction(function () use ($vale, $cajera, $campos, $motivo): SolicitudModificacionVale {
            $solicitud = SolicitudModificacionVale::query()->create(['voucher_id' => $vale->id, 'client_id' => $vale->client_id, 'branch_id' => $vale->branch_id, 'requested_by' => $cajera->id, 'requested_fields' => $campos, 'reason' => $motivo]);
            $vale->forceFill(['status' => 'CORRECTION_PENDING', 'lock_version' => $vale->lock_version + 1])->save();
            AuditHelper::log('VOUCHER_MODIFICATION_REQUESTED', 'voucher_modification_requests', $solicitud->id, $cajera->id, $vale->branch_id, null, ['fields' => $campos], $motivo);

            return $solicitud;
        });
    }

    public function decidir(SolicitudModificacionVale $solicitud, User $autoridad, bool $autorizar, string $motivo, int $version): array
    {
        return DB::transaction(function () use ($solicitud, $autoridad, $autorizar, $motivo, $version): array {
            $solicitud = SolicitudModificacionVale::query()->lockForUpdate()->findOrFail($solicitud->id);
            $this->validarAutoridad($autoridad, $solicitud);
            if ($solicitud->lock_version !== $version) {
                throw new ExcepcionVale('MODIFICATION_VERSION_CONFLICT', 'La solicitud fue modificada.', 409);
            }
            if ($solicitud->status !== 'REQUESTED') {
                throw new ExcepcionVale('MODIFICATION_STATUS_INVALID', 'La solicitud ya fue decidida.', 409);
            }
            $token = $autorizar ? strtoupper(bin2hex(random_bytes(4))) : null;
            $solicitud->forceFill(['status' => $autorizar ? 'AUTHORIZED' : 'REJECTED', 'decided_by' => $autoridad->id, 'decision_reason' => $motivo, 'decided_at' => now(), 'token_hash' => $token ? hash('sha256', $token) : null, 'token_expires_at' => $token ? now()->addMinutes(5) : null, 'lock_version' => $solicitud->lock_version + 1])->save();
            AuditHelper::log($autorizar ? 'VOUCHER_MODIFICATION_AUTHORIZED' : 'VOUCHER_MODIFICATION_REJECTED', 'voucher_modification_requests', $solicitud->id, $autoridad->id, $solicitud->branch_id, ['status' => 'REQUESTED'], ['status' => $solicitud->status, 'fields' => $solicitud->requested_fields], $motivo);

            return ['request' => $solicitud, 'token' => $token, 'expires_at' => $solicitud->token_expires_at?->toIso8601String()];
        });
    }

    public function aplicar(SolicitudModificacionVale $solicitud, User $cajera, string $token, array $cambios, int $version): SolicitudModificacionVale
    {
        return DB::transaction(function () use ($solicitud, $cajera, $token, $cambios, $version): SolicitudModificacionVale {
            $solicitud = SolicitudModificacionVale::query()->lockForUpdate()->findOrFail($solicitud->id);
            $vale = Vale::query()->lockForUpdate()->findOrFail($solicitud->voucher_id);
            $this->validarCajera($cajera, $vale);
            if ($solicitud->requested_by !== $cajera->id) {
                throw new ExcepcionVale('MODIFICATION_TOKEN_ACTOR_MISMATCH', 'El token pertenece a otra cajera.', 403);
            }
            if ($solicitud->lock_version !== $version) {
                throw new ExcepcionVale('MODIFICATION_VERSION_CONFLICT', 'La solicitud fue modificada.', 409);
            }
            if ($solicitud->status !== 'AUTHORIZED' || $solicitud->token_used_at !== null) {
                throw new ExcepcionVale('MODIFICATION_TOKEN_USED', 'El token ya no está disponible.', 409);
            }
            if ($solicitud->token_expires_at->lte(now())) {
                $solicitud->update(['status' => 'EXPIRED']);
                throw new ExcepcionVale('MODIFICATION_TOKEN_EXPIRED', 'El token venció.', 409);
            }
            if (! hash_equals((string) $solicitud->token_hash, hash('sha256', strtoupper(trim($token))))) {
                throw new ExcepcionVale('MODIFICATION_TOKEN_INVALID', 'El token no es válido.', 422);
            }
            if (array_diff(array_keys($cambios), $solicitud->requested_fields) !== []) {
                throw new ExcepcionVale('MODIFICATION_FIELD_NOT_AUTHORIZED', 'Se intentó modificar un campo no autorizado.', 403);
            }

            $cliente = Cliente::query()->lockForUpdate()->findOrFail($solicitud->client_id);
            $anteriores = [];
            $nuevos = [];
            if (array_key_exists('curp', $cambios)) {
                $hmac = $this->protector->hmacCurp($cambios['curp']);
                if (Cliente::query()->where('curp_hmac', $hmac)->whereKeyNot($cliente->id)->exists()) {
                    throw new ExcepcionVale('CLIENT_CURP_EXISTS', 'La CURP ya pertenece a otro cliente.', 409);
                }
                $anteriores['curp_changed'] = false;
                $nuevos['curp_changed'] = true;
                $cliente->forceFill(['curp_ciphertext' => $this->protector->cifrarCurp($cambios['curp']), 'curp_hmac' => $hmac, 'lock_version' => $cliente->lock_version + 1])->save();
            }
            if (array_key_exists('address', $cambios)) {
                $normalizado = $this->normalizador->normalizar($cambios['address']);
                $huella = $this->huella->generar($normalizado);
                if (DomicilioCliente::query()->where('normalized_fingerprint_hmac', $huella)->where('is_current', true)->whereNull('ends_at')->where('client_id', '<>', $cliente->id)->exists()) {
                    throw new ExcepcionVale('CLIENT_ADDRESS_EXISTS', 'El domicilio ya pertenece a otro cliente.', 409);
                }
                $actual = DomicilioCliente::query()->where('client_id', $cliente->id)->where('is_current', true)->whereNull('ends_at')->lockForUpdate()->firstOrFail();
                $anteriores['address_id'] = $actual->id;
                $actual->update(['is_current' => false, 'ends_at' => now(), 'change_reason' => $solicitud->reason]);
                $nuevo = new DomicilioCliente([...$cambios['address'], 'client_id' => $cliente->id, 'is_current' => true, 'country' => $normalizado['country'], 'starts_at' => now(), 'created_by' => $cajera->id, 'change_reason' => $solicitud->reason]);
                $nuevo->forceFill(['normalized_fingerprint_hmac' => $huella])->save();
                $nuevos['address_id'] = $nuevo->id;
            }
            $solicitud->forceFill(['status' => 'APPLIED', 'token_used_at' => now(), 'changes_before' => $anteriores, 'changes_after' => $nuevos, 'lock_version' => $solicitud->lock_version + 1])->save();
            $vale->forceFill(['status' => 'GENERATED', 'lock_version' => $vale->lock_version + 1])->save();
            AuditHelper::log('VOUCHER_MODIFICATION_APPLIED', 'voucher_modification_requests', $solicitud->id, $cajera->id, $vale->branch_id, $anteriores, $nuevos, $solicitud->reason);

            return $solicitud->refresh();
        });
    }

    private function validarCajera(User $actor, Vale $vale): void
    {
        if (! $actor->hasPermissionTo('vouchers.cash_branch') || ! $actor->hasScopeForBranch($vale->branch_id)) {
            throw new ExcepcionVale('VOUCHER_BRANCH_FORBIDDEN', 'Fuera del alcance de caja.', 404);
        }
    }

    private function validarAutoridad(User $actor, SolicitudModificacionVale $solicitud): void
    {
        if (! $actor->hasPermissionTo('voucher_modifications.authorize_global') && (! $actor->hasPermissionTo('voucher_modifications.authorize_branch') || ! $actor->hasScopeForBranch($solicitud->branch_id))) {
            throw new ExcepcionVale('MODIFICATION_AUTHORIZE_FORBIDDEN', 'No puedes autorizar esta modificación.', 403);
        }
    }
}
