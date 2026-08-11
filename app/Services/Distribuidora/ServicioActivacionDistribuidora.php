<?php

namespace App\Services\Distribuidora;

use App\Enums\ApplicationStatus;
use App\Enums\EstadoDistribuidora;
use App\Enums\TipoMovimientoLineaCredito;
use App\Exceptions\ExcepcionDistribuidora;
use App\Mail\ActivationInvitationMail;
use App\Models\AccountInvitation;
use App\Models\ApplicationAuthorization;
use App\Models\ApplicationEvaluation;
use App\Models\ApplicationStateTransition;
use App\Models\AsignacionCategoriaDistribuidora;
use App\Models\Branch;
use App\Models\CategoryVersion;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\Distribuidora;
use App\Models\DistributorApplication;
use App\Models\LineaCredito;
use App\Models\MovimientoLineaCredito;
use App\Models\OutboxEvent;
use App\Models\RestriccionUsoCredito;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Models\VerificationVisit;
use App\Services\ConfiguracionServicio;
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
        $usuarioCreado = null;

        try {
            $distribuidora = DB::transaction(function () use (
                $solicitudId,
                $versionCategoriaId,
                $actor,
                &$token,
                &$usuarioCreado,
            ): Distribuidora {
                $solicitud = DistributorApplication::query()->lockForUpdate()->find($solicitudId);

                if ($solicitud === null) {
                    throw new ExcepcionDistribuidora('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'La solicitud no existe.', 404);
                }

                $existente = Distribuidora::query()->where('application_id', $solicitud->id)->first();
                if ($existente !== null) {
                    return $existente;
                }

                $autorizacion = ApplicationAuthorization::query()
                    ->where('application_id', $solicitud->id)
                    ->lockForUpdate()
                    ->first();

                if ($autorizacion === null) {
                    throw new ExcepcionDistribuidora(
                        'DISTRIBUTOR_APPLICATION_NOT_APPROVED',
                        'La solicitud no cuenta con autorización gerencial.',
                        409,
                    );
                }

                $this->validador->validarSolicitud($solicitud, $autorizacion);

                $visita = VerificationVisit::query()
                    ->where('application_id', $solicitud->id)
                    ->lockForUpdate()
                    ->latest('completed_at')
                    ->first();
                $evaluacion = ApplicationEvaluation::query()
                    ->where('application_id', $solicitud->id)
                    ->lockForUpdate()
                    ->latest('evaluated_at')
                    ->first();
                $this->validador->validarVerificacion($visita, $evaluacion);

                $sucursal = Branch::query()->lockForUpdate()->findOrFail($solicitud->branch_id);
                $this->validador->validarSucursal($sucursal, $solicitud);

                $coordinador = User::query()->lockForUpdate()->find($solicitud->coordinator_id);
                if ($coordinador === null) {
                    throw new ExcepcionDistribuidora(
                        'DISTRIBUTOR_COORDINATOR_SCOPE_INVALID',
                        'La solicitud no tiene un coordinador válido.',
                        409,
                    );
                }
                $this->validador->validarCoordinador($coordinador, $solicitud);

                $versionCategoria = CategoryVersion::query()->with('category')->lockForUpdate()->find($versionCategoriaId);
                if ($versionCategoria === null) {
                    throw new ExcepcionDistribuidora('DISTRIBUTOR_CATEGORY_NOT_EFFECTIVE', 'La categoría no existe.', 422);
                }
                $this->validador->validarCategoria($versionCategoria);

                [$nombre, $email] = $this->datosCuenta($solicitud);
                if (User::query()->where('normalized_email', $email)->exists()) {
                    throw new ExcepcionDistribuidora(
                        'DISTRIBUTOR_USER_CONFLICT',
                        'No fue posible crear la cuenta de acceso.',
                        409,
                    );
                }

                $usuarioCreado = User::create([
                    'name' => $nombre,
                    'email' => $email,
                    'normalized_email' => $email,
                    'state' => 'PENDING_ACTIVATION',
                ]);

                $numeroDistribuidora = $this->generarNumeroDisponible();
                $distribuidora = new Distribuidora([
                    'application_id' => $solicitud->id,
                    'user_id' => $usuarioCreado->id,
                    'distributor_number' => $numeroDistribuidora,
                    'branch_id' => $solicitud->branch_id,
                ]);
                $distribuidora->forceFill([
                    'status' => EstadoDistribuidora::PENDIENTE_ACTIVACION,
                    'lock_version' => 1,
                ])->save();

                $rol = Role::query()->where('code', 'distributor')->first();
                if ($rol === null) {
                    throw new ExcepcionDistribuidora('DISTRIBUTOR_ACTIVATION_STATE_INVALID', 'El rol DISTRIBUTOR no está configurado.', 409);
                }

                UserRoleScope::create([
                    'user_id' => $usuarioCreado->id,
                    'role_id' => $rol->id,
                    'branch_id' => $solicitud->branch_id,
                    'assigned_by_user_id' => $actor->id,
                    'assigned_at' => now(),
                    'assignment_reason' => 'Activación inicial de distribuidora',
                    'scope_type' => 'DISTRIBUTOR',
                    'scope_id' => $distribuidora->id,
                    'status' => 'ACTIVE',
                ]);

                CoordinatorDistributorAssignment::create([
                    'coordinator_id' => $coordinador->id,
                    'distributor_id' => $distribuidora->id,
                    'branch_id' => $solicitud->branch_id,
                    'valid_from' => now(),
                    'status' => 'ACTIVE',
                    'assigned_by' => $actor->id,
                    'assignment_reason' => 'Asignación inicial desde solicitud autorizada',
                ]);

                AsignacionCategoriaDistribuidora::create([
                    'distributor_id' => $distribuidora->id,
                    'category_version_id' => $versionCategoria->id,
                    'starts_at' => now(),
                    'assigned_by' => $actor->id,
                    'reason' => 'Asignación inicial',
                ]);

                $importe = $autorizacion->initial_credit_line_amount;
                $linea = LineaCredito::create([
                    'distributor_id' => $distribuidora->id,
                    'total_authorized' => $importe,
                ]);

                MovimientoLineaCredito::create([
                    'credit_line_id' => $linea->id,
                    'distributor_id' => $distribuidora->id,
                    'sequence' => 1,
                    'type' => TipoMovimientoLineaCredito::AUTORIZACION_INICIAL,
                    'amount' => $importe,
                    'total_authorized_before' => $importe,
                    'total_authorized_after' => $importe,
                    'used_balance_before' => '0.0000',
                    'used_balance_after' => '0.0000',
                    'source_type' => 'DISTRIBUTOR_APPLICATION_AUTHORIZATION',
                    'source_id' => $autorizacion->id,
                    'performed_by' => $actor->id,
                    'authorized_by' => $actor->id,
                    'idempotency_key' => 'initial-authorization:'.$autorizacion->id,
                    'occurred_at' => now(),
                ]);

                $configuracionTolerancia = app(ConfiguracionServicio::class)->resolver('CREDIT_TOLERANCE_AMOUNT');

                RestriccionUsoCredito::create([
                    'credit_line_id' => $linea->id,
                    'distributor_id' => $distribuidora->id,
                    'type' => 'INITIAL_50_PERCENT',
                    'base_total' => $importe,
                    'tolerance_amount' => $configuracionTolerancia['value'],
                    'configuration_version_id' => $configuracionTolerancia['version_id'],
                    'source_type' => 'DISTRIBUTOR_APPLICATION_AUTHORIZATION',
                    'source_id' => $autorizacion->id,
                    'activated_at' => now(),
                    'created_by' => $actor->id,
                ]);

                $token = Str::random(60);
                AccountInvitation::create([
                    'user_id' => $usuarioCreado->id,
                    'created_by_user_id' => $actor->id,
                    'purpose' => 'ACCOUNT_ACTIVATION',
                    'token_hash' => hash('sha256', $token),
                    'state' => 'ACTIVE',
                    'expires_at' => now()->addHours(48),
                ]);

                $estadoAnterior = $solicitud->status;
                $solicitud->status = ApplicationStatus::ACTIVE;
                $solicitud->save();
                ApplicationStateTransition::create([
                    'application_id' => $solicitud->id,
                    'from_status' => $estadoAnterior,
                    'to_status' => ApplicationStatus::ACTIVE,
                    'user_id' => $actor->id,
                    'reason' => 'Distribuidora materializada',
                ]);

                $this->publicarEventos(
                    $distribuidora,
                    $actor,
                    $autorizacion->id,
                    $coordinador->id,
                    $versionCategoria->id,
                    $importe,
                    $estadoAnterior->value,
                );

                return $distribuidora;
            });
        } catch (QueryException $excepcion) {
            $detalle = mb_strtolower((string) ($excepcion->errorInfo[2] ?? ''));
            report($excepcion);
            $codigo = match (true) {
                str_contains($detalle, 'distributors_distributor_number_unique') => 'DISTRIBUTOR_NUMBER_CONFLICT',
                str_contains($detalle, 'distributors_application_id_unique') => 'DISTRIBUTOR_ALREADY_EXISTS',
                str_contains($detalle, 'users_normalized_email_unique') => 'DISTRIBUTOR_USER_CONFLICT',
                default => 'DISTRIBUTOR_ACTIVATION_STATE_INVALID',
            };

            throw new ExcepcionDistribuidora(
                $codigo,
                'No fue posible completar la activación de la distribuidora.',
                409,
            );
        }

        if ($token !== null && $usuarioCreado !== null) {
            Mail::to($usuarioCreado->email)->queue(new ActivationInvitationMail($usuarioCreado, $token));
        }

        return $distribuidora->load([
            'usuario',
            'sucursal',
            'coordinadorVigente.coordinator',
            'categoriaVigente.versionCategoria.category',
            'lineaCredito.movimientos',
            'lineaCredito.restricciones',
        ]);
    }

    private function datosCuenta(DistributorApplication $solicitud): array
    {
        $datos = $solicitud->datosPersonales()->first();
        $email = mb_strtolower(trim((string) $datos?->email));
        $nombre = trim(implode(' ', array_filter([
            $datos?->first_name,
            $datos?->first_last_name,
            $datos?->second_last_name,
        ])));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || $nombre === '') {
            throw new ExcepcionDistribuidora(
                'DISTRIBUTOR_USER_CONFLICT',
                'La solicitud no contiene datos válidos para crear la cuenta.',
                422,
                ['application' => ['Nombre y correo autorizados son obligatorios.']],
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

        throw new ExcepcionDistribuidora(
            'DISTRIBUTOR_NUMBER_CONFLICT',
            'No fue posible asignar un número único a la distribuidora.',
            409,
        );
    }

    private function publicarEventos(
        Distribuidora $distribuidora,
        User $actor,
        string $autorizacionId,
        string $coordinadorId,
        string $versionCategoriaId,
        string $importe,
        string $estadoAnterior,
    ): void {
        $base = [
            'distributor_id' => $distribuidora->id,
            'application_id' => $distribuidora->application_id,
            'authorization_id' => $autorizacionId,
            'branch_id' => $distribuidora->branch_id,
            'user_id' => $distribuidora->user_id,
            'coordinator_id' => $coordinadorId,
            'category_version_id' => $versionCategoriaId,
        ];

        foreach ([
            'DISTRIBUTOR_CREATED' => [],
            'DISTRIBUTOR_NUMBER_ASSIGNED' => ['distributor_number' => $distribuidora->distributor_number],
            'DISTRIBUTOR_COORDINATOR_ASSIGNED' => [],
            'DISTRIBUTOR_CATEGORY_ASSIGNED' => [],
            'INITIAL_CREDIT_LINE_CREATED' => ['amount' => $importe],
            'INITIAL_CREDIT_RESTRICTION_CREATED' => ['type' => 'INITIAL_50_PERCENT'],
            'DISTRIBUTOR_USER_CREATED' => ['user_id' => $distribuidora->user_id],
            'DISTRIBUTOR_ACTIVATION_INVITATION_SENT' => ['user_id' => $distribuidora->user_id],
            'EV-008' => ['amount' => $importe],
            'EV-011' => ['amount' => $importe],
            'EV-012' => ['type' => 'INITIAL_50_PERCENT'],
        ] as $evento => $datos) {
            OutboxEvent::create([
                'event_type' => $evento,
                'payload' => array_merge($base, $datos),
                'status' => 'PENDING',
            ]);

            $this->auditor->registrar(
                $evento,
                'Distributor',
                $distribuidora->id,
                $actor,
                $distribuidora->branch_id,
                ['application_status' => $estadoAnterior],
                array_merge($base, $datos, ['application_status' => ApplicationStatus::ACTIVE->value]),
                'Activación inicial autorizada',
            );
        }
    }
}
