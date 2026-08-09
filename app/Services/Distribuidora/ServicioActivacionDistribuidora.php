<?php

namespace App\Services\Distribuidora;

use App\Enums\EstadoDistribuidora;
use App\Exceptions\ExcepcionDistribuidora;
use App\Mail\ActivationInvitationMail;
use App\Models\AccountInvitation;
use App\Models\ApplicationAuthorization;
use App\Models\AsignacionCategoriaDistribuidora;
use App\Models\Branch;
use App\Models\CategoryVersion;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\Distribuidora;
use App\Models\DistributorApplication;
use App\Models\OutboxEvent;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ServicioActivacionDistribuidora
{
    public function __construct(
        private readonly GeneradorNumeroDistribuidora $generador,
        private readonly ValidadorActivacionDistribuidora $validador,
        private readonly AuditorDistribuidora $auditor,
    ) {}

    public function activar(string $solicitudId, string $versionCategoriaId, User $actor): Distribuidora
    {
        $token = null;
        $destinatario = null;

        try {
            $distribuidora = DB::transaction(function () use (
                $solicitudId,
                $versionCategoriaId,
                $actor,
                &$token,
                &$destinatario,
            ): Distribuidora {
                $solicitud = DistributorApplication::query()->lockForUpdate()->find($solicitudId);
                if ($solicitud === null) {
                    throw new ExcepcionDistribuidora('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'La solicitud no existe.', 404);
                }

                $existente = Distribuidora::query()->where('application_id', $solicitud->id)->first();
                if ($existente !== null) {
                    return $existente->load($this->relaciones());
                }

                $autorizacion = ApplicationAuthorization::query()
                    ->where('application_id', $solicitud->id)
                    ->lockForUpdate()
                    ->first();
                if ($autorizacion === null) {
                    throw new ExcepcionDistribuidora(
                        'DISTRIBUTOR_APPLICATION_NOT_APPROVED',
                        'La solicitud no cuenta con autorizaciÃ³n formal.',
                        409,
                    );
                }
                $this->validador->validarSolicitud($solicitud, $autorizacion);

                $sucursal = Branch::query()->lockForUpdate()->find($solicitud->branch_id);
                if ($sucursal === null) {
                    throw new ExcepcionDistribuidora('DISTRIBUTOR_BRANCH_MISMATCH', 'La sucursal no existe.', 409);
                }
                $this->validador->validarSucursal($sucursal, $solicitud);

                $coordinador = User::query()->lockForUpdate()->find($solicitud->coordinator_id);
                if ($coordinador === null) {
                    throw new ExcepcionDistribuidora(
                        'DISTRIBUTOR_COORDINATOR_SCOPE_INVALID',
                        'La solicitud no tiene un coordinador vÃ¡lido.',
                        409,
                    );
                }
                $this->validador->validarCoordinador($coordinador, $solicitud);

                $versionCategoria = CategoryVersion::query()->with('category')->lockForUpdate()->find($versionCategoriaId);
                if ($versionCategoria === null) {
                    throw new ExcepcionDistribuidora('DISTRIBUTOR_CATEGORY_NOT_EFFECTIVE', 'La categorÃ­a no existe.', 422);
                }
                $this->validador->validarCategoria($versionCategoria);

                [$nombre, $email] = $this->datosCuenta($solicitud);
                [$usuario, $usuarioCreado] = $this->resolverUsuario($nombre, $email);

                $estado = $usuario->state === 'ACTIVE'
                    ? EstadoDistribuidora::ACTIVA
                    : EstadoDistribuidora::PENDIENTE_ACTIVACION;
                $distribuidora = new Distribuidora([
                    'application_id' => $solicitud->id,
                    'user_id' => $usuario->id,
                    'distributor_number' => $this->generarNumeroDisponible(),
                    'branch_id' => $solicitud->branch_id,
                ]);
                $distribuidora->forceFill([
                    'status' => $estado,
                    'activated_at' => $estado === EstadoDistribuidora::ACTIVA ? now() : null,
                    'activated_by' => $estado === EstadoDistribuidora::ACTIVA ? $actor->id : null,
                    'lock_version' => 1,
                ])->save();

                $this->asignarRol($usuario, $distribuidora, $actor);

                CoordinatorDistributorAssignment::query()->create([
                    'coordinator_id' => $coordinador->id,
                    'distributor_id' => $distribuidora->id,
                    'branch_id' => $solicitud->branch_id,
                    'valid_from' => now(),
                    'status' => 'ACTIVE',
                    'assigned_by' => $actor->id,
                    'assignment_reason' => 'AsignaciÃ³n inicial desde autorizaciÃ³n M05',
                ]);

                AsignacionCategoriaDistribuidora::query()->create([
                    'distributor_id' => $distribuidora->id,
                    'category_version_id' => $versionCategoria->id,
                    'starts_at' => now(),
                    'assigned_by' => $actor->id,
                    'reason' => 'AsignaciÃ³n inicial',
                ]);

                $invitacionGenerada = false;
                if ($estado === EstadoDistribuidora::PENDIENTE_ACTIVACION) {
                    [$token, $invitacionGenerada] = $this->asegurarInvitacion($usuario, $actor);
                    if ($invitacionGenerada) {
                        $destinatario = $usuario;
                    }
                }

                $this->publicarEventos(
                    $distribuidora,
                    $actor,
                    $autorizacion->id,
                    $coordinador->id,
                    $versionCategoria->id,
                    $usuarioCreado,
                    $invitacionGenerada,
                );

                return $distribuidora->load($this->relaciones());
            });
        } catch (QueryException $excepcion) {
            $detalle = mb_strtolower((string) ($excepcion->errorInfo[2] ?? ''));
            $codigo = match (true) {
                str_contains($detalle, 'distributors_distributor_number_unique') => 'DISTRIBUTOR_NUMBER_CONFLICT',
                str_contains($detalle, 'distributors_application_id_unique') => 'DISTRIBUTOR_ALREADY_EXISTS',
                str_contains($detalle, 'distributors_user_id_unique') => 'DISTRIBUTOR_USER_CONFLICT',
                default => 'DISTRIBUTOR_ACTIVATION_STATE_INVALID',
            };

            throw new ExcepcionDistribuidora($codigo, 'No fue posible completar el alta de la distribuidora.', 409);
        }

        if ($token !== null && $destinatario !== null) {
            Mail::to($destinatario->email)->queue(new ActivationInvitationMail($destinatario, $token));
        }

        return $distribuidora;
    }

    private function resolverUsuario(string $nombre, string $email): array
    {
        $usuario = User::query()->where('normalized_email', $email)->lockForUpdate()->first();
        if ($usuario !== null) {
            if ($usuario->distribuidora()->exists() || ! in_array($usuario->state, ['ACTIVE', 'PENDING_ACTIVATION'], true)) {
                throw new ExcepcionDistribuidora('DISTRIBUTOR_USER_CONFLICT', 'No fue posible vincular la cuenta de acceso.', 409);
            }

            return [$usuario, false];
        }

        return [User::query()->create([
            'name' => $nombre,
            'email' => $email,
            'normalized_email' => $email,
            'state' => 'PENDING_ACTIVATION',
        ]), true];
    }

    private function asignarRol(User $usuario, Distribuidora $distribuidora, User $actor): void
    {
        $rol = Role::query()->where('code', 'distributor')->first();
        if ($rol === null) {
            throw new ExcepcionDistribuidora('DISTRIBUTOR_ACTIVATION_STATE_INVALID', 'El rol DISTRIBUTOR no estÃ¡ configurado.', 409);
        }

        UserRoleScope::query()->firstOrCreate([
            'user_id' => $usuario->id,
            'role_id' => $rol->id,
            'scope_type' => 'DISTRIBUTOR',
            'scope_id' => $distribuidora->id,
            'status' => 'ACTIVE',
        ], [
            'branch_id' => $distribuidora->branch_id,
            'assigned_by_user_id' => $actor->id,
            'assigned_at' => now(),
            'assignment_reason' => 'Alta operativa de distribuidora',
        ]);
    }

    private function asegurarInvitacion(User $usuario, User $actor): array
    {
        $abierta = AccountInvitation::query()
            ->where('user_id', $usuario->id)
            ->whereIn('state', ['ACTIVE', 'PREPARED'])
            ->where('expires_at', '>', now())
            ->exists();
        if ($abierta) {
            return [null, false];
        }

        AccountInvitation::query()
            ->where('user_id', $usuario->id)
            ->whereIn('state', ['ACTIVE', 'PREPARED'])
            ->update(['state' => 'REVOKED', 'revoked_at' => now(), 'exchange_token_hash' => null]);

        $token = Str::random(60);
        AccountInvitation::query()->create([
            'user_id' => $usuario->id,
            'created_by_user_id' => $actor->id,
            'purpose' => 'ACCOUNT_ACTIVATION',
            'token_hash' => hash('sha256', $token),
            'state' => 'ACTIVE',
            'expires_at' => now()->addHours(48),
        ]);

        return [$token, true];
    }

    private function datosCuenta(DistributorApplication $solicitud): array
    {
        $datos = $solicitud->applicant_data ?? [];
        $email = mb_strtolower(trim((string) (data_get($datos, 'personal_info.email') ?? data_get($datos, 'email'))));
        $nombre = trim(implode(' ', array_filter([
            data_get($datos, 'personal_info.first_name') ?? data_get($datos, 'first_name'),
            data_get($datos, 'personal_info.last_name') ?? data_get($datos, 'last_name'),
            data_get($datos, 'personal_info.second_last_name') ?? data_get($datos, 'second_last_name'),
        ])));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || $nombre === '') {
            throw new ExcepcionDistribuidora(
                'DISTRIBUTOR_USER_CONFLICT',
                'La autorizaciÃ³n no contiene datos vÃ¡lidos para crear o vincular la cuenta.',
                422,
            );
        }

        return [$nombre, $email];
    }

    private function generarNumeroDisponible(): string
    {
        for ($intento = 0; $intento < 5; $intento++) {
            $numero = $this->generador->generar();
            if (! Distribuidora::query()->where('distributor_number', $numero)->exists()) {
                return $numero;
            }
        }

        throw new ExcepcionDistribuidora('DISTRIBUTOR_NUMBER_CONFLICT', 'No fue posible asignar un nÃºmero Ãºnico.', 409);
    }

    private function publicarEventos(
        Distribuidora $distribuidora,
        User $actor,
        string $autorizacionId,
        string $coordinadorId,
        string $versionCategoriaId,
        bool $usuarioCreado,
        bool $invitacionGenerada,
    ): void {
        $base = [
            'distributor_id' => $distribuidora->id,
            'application_id' => $distribuidora->application_id,
            'authorization_id' => $autorizacionId,
            'branch_id' => $distribuidora->branch_id,
            'user_id' => $distribuidora->user_id,
            'coordinator_id' => $coordinadorId,
            'category_version_id' => $versionCategoriaId,
            'status' => $distribuidora->status->value,
        ];
        $eventos = [
            'DISTRIBUTOR_CREATED',
            'DISTRIBUTOR_COORDINATOR_ASSIGNED',
            'DISTRIBUTOR_CATEGORY_ASSIGNED',
            $usuarioCreado ? 'DISTRIBUTOR_USER_CREATED' : 'DISTRIBUTOR_USER_LINKED',
        ];
        if ($invitacionGenerada) {
            $eventos[] = 'DISTRIBUTOR_ACTIVATION_INVITATION_GENERATED';
        }

        foreach ($eventos as $evento) {
            OutboxEvent::query()->create(['event_type' => $evento, 'payload' => $base, 'status' => 'PENDING']);
            $this->auditor->registrar(
                $evento,
                'Distributor',
                $distribuidora->id,
                $actor,
                $distribuidora->branch_id,
                [],
                $base,
                'Alta operativa desde autorizaciÃ³n M05',
            );
        }
    }

    private function relaciones(): array
    {
        return [
            'usuario',
            'sucursal',
            'solicitud.autorizacion',
            'coordinadorVigente.coordinator',
            'categoriaVigente.versionCategoria.category',
            'asignacionesCategoria.versionCategoria.category',
            'asignacionesCoordinador.coordinator',
        ];
    }
}
