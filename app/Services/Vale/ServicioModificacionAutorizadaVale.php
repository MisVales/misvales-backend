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
    private const CAMPOS = ['first_name', 'first_last_name', 'second_last_name', 'birth_date', 'phone_number', 'address', 'curp'];

    private const MOTIVO_SISTEMA = 'CLIENT_DATA_DISCREPANCY';

    private const TOKEN_ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly ProtectorDatosCliente $protector,
        private readonly NormalizadorDomicilio $normalizador,
        private readonly GeneradorHuellaDomicilio $huella,
    ) {}

    public function solicitar(Vale $vale, User $cajera, array $campos, array $cambios, ?string $motivo = null): SolicitudModificacionVale
    {
        $this->validarCajera($cajera, $vale);
        $campos = array_values(array_unique($campos));
        if ($campos === [] || array_diff($campos, self::CAMPOS) !== []) {
            throw new ExcepcionVale('MODIFICATION_FIELDS_INVALID', 'Solo pueden solicitarse campos corregibles autorizados.', 422);
        }
        if (array_diff(array_keys($cambios), $campos) !== [] || array_diff($campos, array_keys($cambios)) !== []) {
            throw new ExcepcionVale('MODIFICATION_FIELDS_INVALID', 'Captura exactamente los campos seleccionados para corrección.', 422);
        }
        if (! in_array($vale->status->value, ['GENERATED', 'CORRECTION_PENDING'], true)) {
            throw new ExcepcionVale('VOUCHER_STATUS_INVALID', 'El estado del vale no permite solicitar corrección.', 409);
        }
        $this->validarCambiosReales($vale, $cambios);

        return DB::transaction(function () use ($vale, $cajera, $campos, $cambios): SolicitudModificacionVale {
            $solicitud = SolicitudModificacionVale::query()->create([
                'voucher_id' => $vale->id,
                'client_id' => $vale->client_id,
                'branch_id' => $vale->branch_id,
                'requested_by' => $cajera->id,
                'requested_fields' => $campos,
                'requested_changes' => $cambios,
                'reason' => self::MOTIVO_SISTEMA,
            ]);
            $vale->forceFill(['status' => 'CORRECTION_PENDING', 'lock_version' => $vale->lock_version + 1])->save();
            AuditHelper::log('VOUCHER_MODIFICATION_REQUESTED', 'voucher_modification_requests', $solicitud->id, $cajera->id, $vale->branch_id, null, ['fields' => $campos, 'reason_code' => self::MOTIVO_SISTEMA]);

            return $solicitud;
        });
    }

    public function decidir(SolicitudModificacionVale $solicitud, User $autoridad, bool $autorizar, ?string $motivo, int $version): array
    {
        return DB::transaction(function () use ($solicitud, $autoridad, $autorizar, $version): array {
            $solicitud = SolicitudModificacionVale::query()->lockForUpdate()->findOrFail($solicitud->id);
            $this->validarAutoridad($autoridad, $solicitud);
            if ($solicitud->lock_version !== $version) {
                throw new ExcepcionVale('MODIFICATION_VERSION_CONFLICT', 'La solicitud fue modificada.', 409);
            }
            if ($solicitud->status !== 'REQUESTED') {
                throw new ExcepcionVale('MODIFICATION_STATUS_INVALID', 'La solicitud ya fue decidida.', 409);
            }
            if ($autorizar && (! is_array($solicitud->requested_changes) || $solicitud->requested_changes === [])) {
                throw new ExcepcionVale('MODIFICATION_CHANGES_MISSING', 'La solicitud no contiene una corrección capturada.', 409);
            }
            $token = $autorizar ? $this->generarToken() : null;
            $solicitud->forceFill([
                'status' => $autorizar ? 'AUTHORIZED' : 'REJECTED',
                'decided_by' => $autoridad->id,
                'decision_reason' => null,
                'decided_at' => now(),
                'token_hash' => $token ? hash('sha256', $token) : null,
                'token_expires_at' => $token ? now()->addMinutes(5) : null,
                'lock_version' => $solicitud->lock_version + 1,
            ])->save();
            if (! $autorizar) {
                $vale = Vale::query()->lockForUpdate()->findOrFail($solicitud->voucher_id);
                $tieneOtraSolicitudActiva = SolicitudModificacionVale::query()->where('voucher_id', $vale->id)->whereKeyNot($solicitud->id)->whereIn('status', ['REQUESTED', 'AUTHORIZED'])->exists();
                if (! $tieneOtraSolicitudActiva) {
                    $vale->forceFill(['status' => 'GENERATED', 'lock_version' => $vale->lock_version + 1])->save();
                }
            }
            AuditHelper::log($autorizar ? 'VOUCHER_MODIFICATION_AUTHORIZED' : 'VOUCHER_MODIFICATION_REJECTED', 'voucher_modification_requests', $solicitud->id, $autoridad->id, $solicitud->branch_id, ['status' => 'REQUESTED'], ['status' => $solicitud->status, 'fields' => $solicitud->requested_fields]);

            return ['request' => $solicitud, 'token' => $token, 'expires_at' => $solicitud->token_expires_at?->toIso8601String()];
        });
    }

    public function aplicar(SolicitudModificacionVale $solicitud, User $cajera, string $token, int $version): SolicitudModificacionVale
    {
        return DB::transaction(function () use ($solicitud, $cajera, $token, $version): SolicitudModificacionVale {
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
            if ($solicitud->token_expires_at === null || $solicitud->token_expires_at->lte(now())) {
                $solicitud->update(['status' => 'EXPIRED']);
                throw new ExcepcionVale('MODIFICATION_TOKEN_EXPIRED', 'El token venció.', 409);
            }
            if (! hash_equals((string) $solicitud->token_hash, hash('sha256', strtoupper(trim($token))))) {
                throw new ExcepcionVale('MODIFICATION_TOKEN_INVALID', 'El token no es válido.', 422);
            }
            $cambios = $solicitud->requested_changes;
            if (! is_array($cambios) || $cambios === [] || array_diff(array_keys($cambios), $solicitud->requested_fields) !== []) {
                throw new ExcepcionVale('MODIFICATION_FIELD_NOT_AUTHORIZED', 'Se intentó modificar un campo no autorizado.', 403);
            }

            $cliente = Cliente::query()->lockForUpdate()->findOrFail($solicitud->client_id);
            $anteriores = [];
            $nuevos = [];
            foreach (['first_name', 'first_last_name', 'second_last_name', 'birth_date', 'phone_number'] as $campo) {
                if (array_key_exists($campo, $cambios)) {
                    $anteriores[$campo] = $cliente->{$campo};
                    $nuevos[$campo] = $cambios[$campo];
                    $cliente->forceFill([$campo => $cambios[$campo]])->save();
                }
            }
            if (array_key_exists('curp', $cambios)) {
                $hmac = $this->protector->hmacCurp($cambios['curp']);
                if (Cliente::query()->where('curp_hmac', $hmac)->whereKeyNot($cliente->id)->exists()) {
                    throw new ExcepcionVale('CLIENT_CURP_EXISTS', 'La CURP ya pertenece a otro cliente.', 409);
                }
                $anteriores['curp_hmac'] = $cliente->curp_hmac;
                $nuevos['curp_hmac'] = $hmac;
                $cliente->forceFill(['curp_ciphertext' => $this->protector->cifrarCurp($cambios['curp']), 'curp_hmac' => $hmac])->save();
            }
            if (array_key_exists('address', $cambios)) {
                $normalizado = $this->normalizador->normalizar($cambios['address']);
                $huella = $this->huella->generar($normalizado);
                if (DomicilioCliente::query()->where('normalized_fingerprint_hmac', $huella)->where('is_current', true)->whereNull('ends_at')->where('client_id', '<>', $cliente->id)->exists()) {
                    throw new ExcepcionVale('CLIENT_ADDRESS_EXISTS', 'El domicilio ya pertenece a otro cliente.', 409);
                }
                $actual = DomicilioCliente::query()->where('client_id', $cliente->id)->where('is_current', true)->whereNull('ends_at')->lockForUpdate()->first();
                $anteriores['address'] = $actual?->only(['street', 'exterior_number', 'interior_number', 'neighborhood', 'postal_code', 'municipality', 'city', 'state', 'country']);
                $actual?->update(['is_current' => false, 'ends_at' => now(), 'change_reason' => self::MOTIVO_SISTEMA]);
                $nuevo = new DomicilioCliente([...$cambios['address'], 'client_id' => $cliente->id, 'is_current' => true, 'country' => $normalizado['country'], 'address_proof_media_id' => $actual?->address_proof_media_id, 'starts_at' => now(), 'created_by' => $cajera->id, 'change_reason' => self::MOTIVO_SISTEMA]);
                $nuevo->forceFill(['normalized_fingerprint_hmac' => $huella])->save();
                $nuevos['address'] = $nuevo->only(['street', 'exterior_number', 'interior_number', 'neighborhood', 'postal_code', 'municipality', 'city', 'state', 'country']);
            }
            if ($nuevos !== []) {
                $cliente->forceFill(['lock_version' => $cliente->lock_version + 1])->save();
            }
            $solicitud->forceFill(['status' => 'APPLIED', 'token_used_at' => now(), 'changes_before' => $anteriores, 'changes_after' => $nuevos, 'lock_version' => $solicitud->lock_version + 1])->save();
            $vale->forceFill(['status' => 'GENERATED', 'lock_version' => $vale->lock_version + 1])->save();
            AuditHelper::log('VOUCHER_MODIFICATION_APPLIED', 'voucher_modification_requests', $solicitud->id, $cajera->id, $vale->branch_id, $anteriores, $nuevos);

            return $solicitud->refresh();
        });
    }

    private function generarToken(): string
    {
        $token = '';
        for ($i = 0; $i < 8; $i++) {
            $token .= self::TOKEN_ALFABETO[random_int(0, strlen(self::TOKEN_ALFABETO) - 1)];
        }

        return $token;
    }

    private function validarCajera(User $actor, Vale $vale): void
    {
        if (! $actor->hasPermissionTo('vouchers.cash_branch') || ! $actor->hasScopeForBranch($vale->branch_id)) {
            throw new ExcepcionVale('VOUCHER_BRANCH_FORBIDDEN', 'Fuera del alcance de caja.', 404);
        }
    }

    private function validarCambiosReales(Vale $vale, array $cambios): void
    {
        $cliente = Cliente::query()->with('domicilioVigente')->findOrFail($vale->client_id);
        $sinCambios = [];
        foreach (['first_name' => 'nombre', 'first_last_name' => 'apellido paterno', 'second_last_name' => 'apellido materno', 'birth_date' => 'fecha de nacimiento', 'phone_number' => 'teléfono'] as $campo => $label) {
            if (array_key_exists($campo, $cambios) && (string) $cliente->{$campo} === (string) $cambios[$campo]) {
                $sinCambios[] = $label;
            }
        }
        if (array_key_exists('curp', $cambios) && $cliente->curp_hmac !== null && hash_equals((string) $cliente->curp_hmac, $this->protector->hmacCurp($cambios['curp']))) {
            $sinCambios[] = 'CURP';
        }
        if (array_key_exists('address', $cambios) && $cliente->domicilioVigente !== null && $this->normalizador->serializar($cliente->domicilioVigente->toArray()) === $this->normalizador->serializar($cambios['address'])) {
            $sinCambios[] = 'domicilio';
        }
        if ($sinCambios !== []) {
            throw new ExcepcionVale('MODIFICATION_NO_CHANGES', 'No se detectaron cambios en: '.implode(', ', $sinCambios).'. Captura un valor diferente al actual.', 422);
        }
    }

    private function validarAutoridad(User $actor, SolicitudModificacionVale $solicitud): void
    {
        $esGerenteGeneral = $actor->hasRole('general_manager') && $actor->hasPermissionTo('voucher_modifications.authorize_global');
        $esGerenteSucursal = $actor->hasRole('branch_manager') && $actor->hasPermissionTo('voucher_modifications.authorize_branch') && $actor->hasScopeForBranch($solicitud->branch_id);
        if (! $esGerenteGeneral && ! $esGerenteSucursal) {
            throw new ExcepcionVale('MODIFICATION_AUTHORIZE_FORBIDDEN', 'Solo un gerente general o de sucursal puede autorizar esta modificación.', 403);
        }
    }
}
