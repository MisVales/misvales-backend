<?php

namespace App\Services\SolicitudDistribuidora;

use App\Enums\EstadoSolicitudDistribuidora;
use App\Models\CreditoComercialSolicitud;
use App\Models\DatosPersonalesSolicitud;
use App\Models\DomicilioSolicitud;
use App\Models\EmpleoSolicitud;
use App\Models\FamiliarSolicitud;
use App\Models\MediaFileBinding;
use App\Models\PatrimonioSolicitud;
use App\Models\SolicitudDistribuidora;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Models\VehiculoSolicitud;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ServicioSolicitudDistribuidora
{
    /** @var array<string, array<string, string>> */
    private array $declaracionesCache = [];

    public function __construct(
        private readonly GeneradorFolioSolicitud $generadorFolio,
        private readonly ProtectorDatosSolicitud $protectorDatos,
        private readonly ValidadorExpedienteSolicitud $validadorExpediente,
        private readonly AuditorSolicitudDistribuidora $auditor,
    ) {}

    /** @param array<string, mixed> $datos */
    public function crearSolicitud(User $actor, array $datos): SolicitudDistribuidora
    {
        return DB::transaction(function () use ($actor, $datos): SolicitudDistribuidora {
            $sucursal = BranchRecord::query()->find($datos['branch_id']);
            $coordinador = User::query()->find($datos['coordinator_id']);

            if ($sucursal === null || $sucursal->status !== 'ACTIVE') {
                throw ValidationException::withMessages(['branch_id' => ['La sucursal no existe o no está activa.']]);
            }

            if (! $this->actorPuedeCrearEnSucursal($actor, $sucursal->id, $coordinador?->id)) {
                throw new AuthorizationException('La sucursal no está dentro del alcance autorizado.');
            }

            if ($coordinador === null || $coordinador->state !== 'ACTIVE' || ! $this->esCoordinadorDeSucursal($coordinador->id, $sucursal->id)) {
                throw ValidationException::withMessages(['coordinator_id' => ['El coordinador no tiene una asignación vigente en la sucursal.']]);
            }

            $solicitud = new SolicitudDistribuidora;
            $solicitud->forceFill([
                'id' => (string) str()->uuid(),
                'application_number' => $this->generadorFolio->generar(),
                'branch_id' => $sucursal->id,
                'coordinator_id' => $coordinador->id,
                'status' => EstadoSolicitudDistribuidora::BORRADOR->value,
                'section_declarations' => $this->declaracionesIniciales(),
                'created_by' => $actor->id,
                'lock_version' => 1,
            ])->save();

            $this->auditor->registrar($actor, $solicitud, 'DISTRIBUTOR_APPLICATION_CREATED', [], ['status' => 'DRAFT']);

            return $solicitud->load(['sucursal:id,name', 'coordinador:id,name']);
        });
    }

    /** @param array<string, mixed> $filtros */
    public function listarSolicitudes(User $actor, array $filtros): LengthAwarePaginator
    {
        $consulta = SolicitudDistribuidora::query()
            ->with(['sucursal:id,name', 'coordinador:id,name', 'datosPersonales'])
            ->tap(fn (Builder $query) => $this->aplicarAlcance($query, $actor));

        foreach (['application_number', 'status', 'branch_id', 'coordinator_id'] as $campo) {
            if (isset($filtros[$campo])) {
                $consulta->where($campo, $filtros[$campo]);
            }
        }

        foreach ([['created_from', 'created_at', '>='], ['created_to', 'created_at', '<='], ['submitted_from', 'submitted_at', '>='], ['submitted_to', 'submitted_at', '<=']] as [$entrada, $columna, $operador]) {
            if (isset($filtros[$entrada])) {
                $consulta->whereDate($columna, $operador, $filtros[$entrada]);
            }
        }

        return $consulta
            ->orderBy($filtros['sort'] ?? 'created_at', $filtros['direction'] ?? 'desc')
            ->paginate((int) ($filtros['per_page'] ?? 15), ['*'], 'page', (int) ($filtros['page'] ?? 1));
    }

    public function consultarSolicitud(User $actor, SolicitudDistribuidora $solicitud): SolicitudDistribuidora
    {
        $permitida = SolicitudDistribuidora::query()
            ->whereKey($solicitud->id)
            ->tap(fn (Builder $query) => $this->aplicarAlcance($query, $actor))
            ->with([
                'sucursal:id,name', 'coordinador:id,name', 'datosPersonales', 'familiares',
                'domicilios', 'vehiculos', 'patrimonio', 'empleos', 'creditosComerciales',
                'verificationVisits.mediaFiles', 'corrections', 'evaluations',
                'latestEvaluation', 'authorization',
            ])
            ->first();

        if ($permitida === null) {
            throw new AuthorizationException('La solicitud no está dentro del alcance autorizado.');
        }

        $ownerIds = [
            'application_vehicle' => $permitida->vehiculos->modelKeys(),
            'application_asset_liability' => $permitida->patrimonio->modelKeys(),
            'application_commercial_credit' => $permitida->creditosComerciales->modelKeys(),
        ];
        $bindings = MediaFileBinding::query()
            ->with('mediaFile')
            ->where(function ($query) use ($permitida, $ownerIds): void {
                $query->where(function ($query) use ($permitida): void {
                    $query->where('owner_type', 'distributor_application')
                        ->where('owner_id', $permitida->id)
                        ->whereIn('purpose', ['IDENTIFICATION', 'ADDRESS_PROOF', 'VEHICLE_EVIDENCE', 'ASSET_EVIDENCE', 'COMMERCIAL_EVIDENCE']);
                });
                foreach ($ownerIds as $ownerType => $ids) {
                    if ($ids !== []) {
                        $query->orWhere(fn ($query) => $query->where('owner_type', $ownerType)->whereIn('owner_id', $ids));
                    }
                }
            })
            ->get();
        $permitida->setRelation('declaredMediaFiles', $bindings->pluck('mediaFile')->filter()->unique('id')->values());

        return $permitida;
    }

    /** @param array<string, mixed> $datos */
    public function actualizarSolicitud(User $actor, SolicitudDistribuidora $solicitud, array $datos): SolicitudDistribuidora
    {
        return DB::transaction(function () use ($actor, $solicitud, $datos): SolicitudDistribuidora {
            $bloqueada = SolicitudDistribuidora::query()->lockForUpdate()->findOrFail($solicitud->id);

            if ($bloqueada->status !== EstadoSolicitudDistribuidora::BORRADOR) {
                throw new ConflictHttpException('La solicitud ya no puede modificarse.');
            }

            if ($bloqueada->lock_version !== (int) $datos['lock_version']) {
                throw new ConflictHttpException('La versión de la solicitud está desactualizada.');
            }

            if (! $this->actorPuedeOperar($actor, $bloqueada)) {
                throw new AuthorizationException('La solicitud no está dentro del alcance autorizado.');
            }

            $branchId = $datos['branch_id'] ?? $bloqueada->branch_id;
            $coordinatorId = $datos['coordinator_id'] ?? $bloqueada->coordinator_id;
            $sucursal = BranchRecord::query()->find($branchId);

            if ($sucursal === null || $sucursal->status !== 'ACTIVE') {
                throw ValidationException::withMessages(['branch_id' => ['La sucursal no existe o no está activa.']]);
            }

            if (! $this->actorPuedeCrearEnSucursal($actor, $branchId, $coordinatorId)
                || ! $this->esCoordinadorDeSucursal($coordinatorId, $branchId)) {
                throw new AuthorizationException('La reasignación solicitada está fuera del alcance autorizado.');
            }

            $bloqueada->forceFill([
                'branch_id' => $branchId,
                'coordinator_id' => $coordinatorId,
                'lock_version' => $bloqueada->lock_version + 1,
            ])->save();

            $this->auditor->registrar($actor, $bloqueada, 'DISTRIBUTOR_APPLICATION_UPDATED', [
                'branch_id' => $solicitud->branch_id, 'coordinator_id' => $solicitud->coordinator_id,
            ], ['branch_id' => $branchId, 'coordinator_id' => $coordinatorId]);

            return $bloqueada->refresh()->load(['sucursal:id,name', 'coordinador:id,name']);
        });
    }

    /** @param array<string, mixed> $datos */
    public function guardarDatosPersonales(User $actor, SolicitudDistribuidora $solicitud, array $datos): DatosPersonalesSolicitud
    {
        return DB::transaction(function () use ($actor, $solicitud, $datos): DatosPersonalesSolicitud {
            $bloqueada = $this->bloquearBorradorEditable($actor, $solicitud->id, (int) $datos['lock_version']);
            $registro = DatosPersonalesSolicitud::query()->firstOrNew(['application_id' => $bloqueada->id]);
            $rfc = array_key_exists('rfc', $datos) ? $this->limpiarTexto($datos['rfc']) : null;
            $curp = array_key_exists('curp', $datos) ? $this->limpiarTexto($datos['curp']) : null;
            $officialIdNumber = array_key_exists('official_id_number', $datos)
                ? $this->limpiarTexto($datos['official_id_number'])
                : null;

            $curpHmac = $curp === null ? null : $this->protectorDatos->generarHmacCurp($curp);
            if ($curpHmac !== null && DatosPersonalesSolicitud::query()
                ->where('curp_hmac', $curpHmac)
                ->where('application_id', '<>', $bloqueada->id)
                ->exists()) {
                throw ValidationException::withMessages([
                    'curp' => ['La CURP ya está registrada en otra solicitud.'],
                ]);
            }

            $officialIdHmac = $officialIdNumber === null ? null : $this->protectorDatos->generarHmacIdentificacion($officialIdNumber);
            if (($datos['nationality'] ?? $registro->nationality) === 'FOREIGN'
                && $officialIdHmac !== null
                && DatosPersonalesSolicitud::query()
                    ->where('nationality', 'FOREIGN')
                    ->where('identification_country', $datos['identification_country'] ?? $registro->identification_country)
                    ->where('official_id_type', $datos['official_id_type'] ?? $registro->official_id_type)
                    ->where('official_id_number_hmac', $officialIdHmac)
                    ->where('application_id', '<>', $bloqueada->id)
                    ->exists()) {
                throw ValidationException::withMessages([
                    'official_id_number' => ['El número de identificación ya está registrado en otra solicitud.'],
                ]);
            }

            // El autoguardado manda únicamente los campos ya válidos. No se
            // deben convertir en null los demás valores existentes.
            $atributos = [];
            foreach (['first_name', 'first_last_name', 'second_last_name'] as $campo) {
                if (array_key_exists($campo, $datos)) {
                    $atributos[$campo] = $this->normalizarNombre($datos[$campo]);
                }
            }
            foreach (['nationality', 'birth_date', 'identification_country', 'official_id_type'] as $campo) {
                if (array_key_exists($campo, $datos)) {
                    $atributos[$campo] = $datos[$campo];
                }
            }
            foreach (['birth_country', 'birth_state', 'birth_city', 'phone_number'] as $campo) {
                if (array_key_exists($campo, $datos)) {
                    $atributos[$campo] = $this->limpiarTexto($datos[$campo]);
                }
            }
            if (array_key_exists('birth_place', $datos)) {
                $atributos['birth_place'] = $this->limpiarTexto($datos['birth_place']);
            } elseif (array_key_exists('birth_country', $datos)
                || array_key_exists('birth_state', $datos)
                || array_key_exists('birth_city', $datos)) {
                $atributos['birth_place'] = $this->formatearLugarNacimiento(
                    $atributos['birth_city'] ?? $registro->birth_city,
                    $atributos['birth_state'] ?? $registro->birth_state,
                    $atributos['birth_country'] ?? $registro->birth_country,
                );
            }
            if (array_key_exists('email', $datos)) {
                $email = $this->limpiarTexto($datos['email']);
                $atributos['email'] = $email === null ? null : mb_strtolower($email);
            }
            if (array_key_exists('curp', $datos)) {
                $atributos['curp_ciphertext'] = $curp === null ? null : $this->protectorDatos->cifrarCurp($curp);
                $atributos['curp_hmac'] = $curpHmac;
            }
            if (array_key_exists('rfc', $datos)) {
                $atributos['rfc_ciphertext'] = $rfc === null ? null : $this->protectorDatos->cifrarRfc($rfc);
                $atributos['rfc_hmac'] = $rfc === null ? null : $this->protectorDatos->generarHmacRfc($rfc);
            }
            if (array_key_exists('official_id_number', $datos)) {
                $atributos['official_id_number_ciphertext'] = $officialIdNumber === null ? null : $this->protectorDatos->cifrarIdentificacion($officialIdNumber);
                $atributos['official_id_number_hmac'] = $officialIdHmac;
            }

            $registro->forceFill($atributos)->save();

            $this->incrementarVersion($bloqueada);
            $this->auditor->registrar($actor, $bloqueada, 'DISTRIBUTOR_APPLICATION_PERSONAL_DATA_UPDATED', [], ['fields_updated' => array_keys($datos)]);

            return $registro->refresh()->setAttribute('application_lock_version', $bloqueada->lock_version);
        });
    }

    /** @return Collection<int, DomicilioSolicitud> */
    public function listarDomicilios(User $actor, SolicitudDistribuidora $solicitud)
    {
        $this->asegurarConsultaEnAlcance($actor, $solicitud);

        return $solicitud->domicilios()->orderByDesc('is_current')->orderBy('created_at')->get();
    }

    /** @param array<string, mixed> $datos */
    public function guardarDomicilio(User $actor, SolicitudDistribuidora $solicitud, array $datos, ?DomicilioSolicitud $domicilio = null): DomicilioSolicitud
    {
        return DB::transaction(function () use ($actor, $solicitud, $datos, $domicilio): DomicilioSolicitud {
            $bloqueada = $this->bloquearBorradorEditable($actor, $solicitud->id, (int) $datos['lock_version']);

            if ($domicilio !== null && $domicilio->application_id !== $bloqueada->id) {
                throw new AuthorizationException('El domicilio no pertenece a la solicitud.');
            }

            $registro = $domicilio ?? new DomicilioSolicitud;
            $atributos = collect($datos)->except('lock_version')->all();
            $atributos['application_id'] = $bloqueada->id;
            $atributos['country'] ??= 'MX';

            if (($atributos['is_current'] ?? $registro->is_current ?? false)
                && DomicilioSolicitud::query()
                    ->where('application_id', $bloqueada->id)
                    ->where('is_current', true)
                    ->when($registro->exists, fn (Builder $query) => $query->whereKeyNot($registro->id))
                    ->exists()) {
                throw ValidationException::withMessages(['is_current' => ['La solicitud ya tiene un domicilio actual.']]);
            }

            $registro->fill($atributos)->save();
            $this->incrementarVersion($bloqueada);
            $this->auditor->registrar($actor, $bloqueada, $domicilio === null ? 'DISTRIBUTOR_APPLICATION_RESIDENCE_ADDED' : 'DISTRIBUTOR_APPLICATION_RESIDENCE_UPDATED', [], ['residence_id' => $registro->id]);

            return $registro->refresh()->setAttribute('application_lock_version', $bloqueada->lock_version);
        });
    }

    public function eliminarDomicilio(User $actor, SolicitudDistribuidora $solicitud, DomicilioSolicitud $domicilio, int $version): void
    {
        DB::transaction(function () use ($actor, $solicitud, $domicilio, $version): void {
            $bloqueada = $this->bloquearBorradorEditable($actor, $solicitud->id, $version);

            if ($domicilio->application_id !== $bloqueada->id) {
                throw new AuthorizationException('El domicilio no pertenece a la solicitud.');
            }

            $domicilio->delete();
            $this->incrementarVersion($bloqueada);
            $this->auditor->registrar($actor, $bloqueada, 'DISTRIBUTOR_APPLICATION_RESIDENCE_REMOVED', ['residence_id' => $domicilio->id]);
        });
    }

    public function enviarARevision(User $actor, SolicitudDistribuidora $solicitud, int $version): SolicitudDistribuidora
    {
        return DB::transaction(function () use ($actor, $solicitud, $version): SolicitudDistribuidora {
            $bloqueada = $this->bloquearBorradorEditable($actor, $solicitud->id, $version, true);
            $this->validadorExpediente->validarEnvio($bloqueada);
            $bloqueada->forceFill([
                'status' => EstadoSolicitudDistribuidora::REVISION_COORDINADOR->value,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'lock_version' => $bloqueada->lock_version + 1,
            ])->save();
            $this->auditor->registrar($actor, $bloqueada, 'DISTRIBUTOR_APPLICATION_SUBMITTED', ['status' => 'DRAFT'], ['status' => 'COORDINATOR_REVIEW']);

            return $bloqueada->refresh()->load(['sucursal:id,name', 'coordinador:id,name', 'datosPersonales']);
        });
    }

    public function listarFamiliares(User $actor, SolicitudDistribuidora $solicitud)
    {
        return $this->listarRegistros($actor, $solicitud, 'familiares');
    }

    public function guardarFamiliar(User $actor, SolicitudDistribuidora $solicitud, array $datos, ?FamiliarSolicitud $registro = null): FamiliarSolicitud
    {
        foreach (['first_name', 'first_last_name', 'second_last_name'] as $campo) {
            if (isset($datos[$campo])) {
                $datos[$campo] = $this->normalizarNombre($datos[$campo]);
            }
        }

        // Esta colección corresponde exclusivamente a referencias familiares.
        // El indicador heredado deja de depender de una selección manual.
        $datos['is_family_reference'] = true;

        /** @var FamiliarSolicitud */
        return $this->guardarRegistro($actor, $solicitud, $datos, $registro ?? new FamiliarSolicitud);
    }

    public function listarVehiculos(User $actor, SolicitudDistribuidora $solicitud)
    {
        return $this->listarRegistros($actor, $solicitud, 'vehiculos');
    }

    public function guardarVehiculo(User $actor, SolicitudDistribuidora $solicitud, array $datos, ?VehiculoSolicitud $registro = null): VehiculoSolicitud
    {
        /** @var VehiculoSolicitud */
        return $this->guardarRegistro($actor, $solicitud, $datos, $registro ?? new VehiculoSolicitud);
    }

    public function listarPatrimonio(User $actor, SolicitudDistribuidora $solicitud)
    {
        return $this->listarRegistros($actor, $solicitud, 'patrimonio');
    }

    public function guardarPatrimonio(User $actor, SolicitudDistribuidora $solicitud, array $datos, ?PatrimonioSolicitud $registro = null): PatrimonioSolicitud
    {
        /** @var PatrimonioSolicitud */
        return $this->guardarRegistro($actor, $solicitud, $datos, $registro ?? new PatrimonioSolicitud);
    }

    public function listarEmpleos(User $actor, SolicitudDistribuidora $solicitud)
    {
        return $this->listarRegistros($actor, $solicitud, 'empleos');
    }

    public function guardarEmpleo(User $actor, SolicitudDistribuidora $solicitud, array $datos, ?EmpleoSolicitud $registro = null): EmpleoSolicitud
    {
        $inicio = $datos['started_at'] ?? $registro?->started_at?->format('Y-m-d');
        $fin = array_key_exists('ended_at', $datos)
            ? $datos['ended_at']
            : $registro?->ended_at?->format('Y-m-d');

        if (isset($datos['is_current']) && $datos['is_current']) {
            $datos['ended_at'] = null;
            $fin = null;
        }

        if ($inicio !== null && $fin !== null && $fin < $inicio) {
            throw ValidationException::withMessages([
                'ended_at' => ['La fecha de término debe ser posterior o igual a la fecha de inicio.'],
            ]);
        }

        /** @var EmpleoSolicitud */
        return $this->guardarRegistro($actor, $solicitud, $datos, $registro ?? new EmpleoSolicitud);
    }

    public function listarCreditosComerciales(User $actor, SolicitudDistribuidora $solicitud)
    {
        return $this->listarRegistros($actor, $solicitud, 'creditosComerciales');
    }

    public function guardarCreditoComercial(User $actor, SolicitudDistribuidora $solicitud, array $datos, ?CreditoComercialSolicitud $registro = null): CreditoComercialSolicitud
    {
        /** @var CreditoComercialSolicitud */
        return $this->guardarRegistro($actor, $solicitud, $datos, $registro ?? new CreditoComercialSolicitud);
    }

    public function eliminarRegistroDeBorrador(User $actor, SolicitudDistribuidora $solicitud, Model $registro, int $version): void
    {
        DB::transaction(function () use ($actor, $solicitud, $registro, $version): void {
            $bloqueada = $this->bloquearBorradorEditable($actor, $solicitud->id, $version);

            if ($registro->getAttribute('application_id') !== $bloqueada->id) {
                throw new AuthorizationException('El registro no pertenece a la solicitud.');
            }

            $registro->delete();
            $this->incrementarVersion($bloqueada);
            $this->auditor->registrar($actor, $bloqueada, $this->eventoRegistro($registro, 'REMOVED'), ['record_id' => $registro->getKey()]);
        });
    }

    private function aplicarAlcance(Builder $consulta, User $actor): void
    {
        $asignaciones = $this->asignacionesActivas($actor);
        $global = $asignaciones->contains(fn ($asignacion): bool => in_array($asignacion->role_code, ['general_manager', 'admin'], true)
            && $asignacion->scope_type === 'GLOBAL');

        if ($global) {
            return;
        }

        $sucursalesGerente = $asignaciones
            ->where('role_code', 'branch_manager')
            ->pluck('branch_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $sucursalesCoordinador = $asignaciones
            ->where('role_code', 'coordinator')
            ->pluck('branch_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($sucursalesGerente) && empty($sucursalesCoordinador)) {
            $consulta->whereRaw('1 = 0');
            return;
        }

        $consulta->where(function (Builder $query) use ($sucursalesGerente, $sucursalesCoordinador, $actor) {
            if (!empty($sucursalesGerente)) {
                $query->orWhereIn('branch_id', $sucursalesGerente);
            }

            if (!empty($sucursalesCoordinador)) {
                $query->orWhere(function (Builder $subQuery) use ($sucursalesCoordinador, $actor) {
                    $subQuery->whereIn('branch_id', $sucursalesCoordinador)
                        ->where(function (Builder $q) use ($actor) {
                            $q->where('coordinator_id', $actor->id)
                              ->orWhere('created_by', $actor->id);
                        });
                });
            }
        });
    }

    private function listarRegistros(User $actor, SolicitudDistribuidora $solicitud, string $relacion)
    {
        $this->asegurarConsultaEnAlcance($actor, $solicitud);

        $registros = $solicitud->{$relacion}()->orderBy('created_at')->get();
        $ownerType = match ($relacion) {
            'vehiculos' => 'application_vehicle',
            'patrimonio' => 'application_asset_liability',
            'creditosComerciales' => 'application_commercial_credit',
            default => null,
        };

        if ($ownerType !== null && $registros->isNotEmpty()) {
            $conEvidencia = MediaFileBinding::query()
                ->where('owner_type', $ownerType)
                ->whereIn('owner_id', $registros->modelKeys())
                ->pluck('owner_id')
                ->all();

            $registros->each(fn (Model $registro) => $registro->setAttribute('has_evidence', in_array($registro->getKey(), $conEvidencia, true)));
        }

        return $registros;
    }

    private function guardarRegistro(User $actor, SolicitudDistribuidora $solicitud, array $datos, Model $registro): Model
    {
        return DB::transaction(function () use ($actor, $solicitud, $datos, $registro): Model {
            $bloqueada = $this->bloquearBorradorEditable($actor, $solicitud->id, (int) $datos['lock_version']);

            if ($registro->exists && $registro->getAttribute('application_id') !== $bloqueada->id) {
                throw new AuthorizationException('El registro no pertenece a la solicitud.');
            }

            $atributos = collect($datos)->except('lock_version')->all();
            $atributos['application_id'] = $bloqueada->id;
            $eraNuevo = ! $registro->exists;
            $registro->fill($atributos)->save();
            $this->incrementarVersion($bloqueada);
            $this->auditor->registrar($actor, $bloqueada, $this->eventoRegistro($registro, $eraNuevo ? 'ADDED' : 'UPDATED'), [], ['record_id' => $registro->getKey()]);

            return $registro->refresh()->setAttribute('application_lock_version', $bloqueada->lock_version);
        });
    }

    private function bloquearBorradorEditable(User $actor, string $solicitudId, int $version, bool $esEnvio = false): SolicitudDistribuidora
    {
        $solicitud = SolicitudDistribuidora::query()->lockForUpdate()->findOrFail($solicitudId);

        if ($solicitud->status !== EstadoSolicitudDistribuidora::BORRADOR) {
            throw new ConflictHttpException($esEnvio ? 'La solicitud ya fue enviada.' : 'La solicitud ya no puede modificarse.');
        }

        if ($solicitud->lock_version !== $version) {
            throw new ConflictHttpException('La versión de la solicitud está desactualizada.');
        }

        if (! $this->actorPuedeOperar($actor, $solicitud)) {
            throw new AuthorizationException('La solicitud no está dentro del alcance autorizado.');
        }

        return $solicitud;
    }

    private function asegurarConsultaEnAlcance(User $actor, SolicitudDistribuidora $solicitud): void
    {
        $permitida = SolicitudDistribuidora::query()
            ->whereKey($solicitud->id)
            ->tap(fn (Builder $query) => $this->aplicarAlcance($query, $actor))
            ->exists();

        if (! $permitida) {
            throw new AuthorizationException('La solicitud no está dentro del alcance autorizado.');
        }
    }

    private function incrementarVersion(SolicitudDistribuidora $solicitud): void
    {
        $solicitud->forceFill(['lock_version' => $solicitud->lock_version + 1])->save();
    }

    private function normalizarNombre(?string $nombre): ?string
    {
        $normalizado = $this->limpiarTexto($nombre);

        return $normalizado === null ? null : mb_convert_case($normalizado, MB_CASE_TITLE, 'UTF-8');
    }

    private function limpiarTexto(?string $texto): ?string
    {
        if ($texto === null) {
            return null;
        }

        $normalizado = (string) preg_replace('/\s+/', ' ', trim($texto));

        return $normalizado === '' ? null : $normalizado;
    }

    private function formatearLugarNacimiento(?string ...$partes): ?string
    {
        $partesPresentes = array_values(array_filter($partes, fn (?string $parte) => $parte !== null));

        return $partesPresentes === [] ? null : implode(', ', $partesPresentes);
    }

    private function eventoRegistro(Model $registro, string $accion): string
    {
        $base = match (true) {
            $registro instanceof FamiliarSolicitud => 'DISTRIBUTOR_APPLICATION_FAMILY_MEMBER',
            $registro instanceof VehiculoSolicitud => 'DISTRIBUTOR_APPLICATION_VEHICLE',
            $registro instanceof PatrimonioSolicitud => 'DISTRIBUTOR_APPLICATION_ASSET_LIABILITY',
            $registro instanceof EmpleoSolicitud => 'DISTRIBUTOR_APPLICATION_EMPLOYMENT',
            $registro instanceof CreditoComercialSolicitud => 'DISTRIBUTOR_APPLICATION_COMMERCIAL_CREDIT',
            default => 'DISTRIBUTOR_APPLICATION_RECORD',
        };

        if (in_array($registro::class, [PatrimonioSolicitud::class, EmpleoSolicitud::class, CreditoComercialSolicitud::class], true)) {
            return $base.'_UPDATED';
        }

        return $base.'_'.$accion;
    }

    private function actorPuedeCrearEnSucursal(User $actor, string $branchId, ?string $coordinatorId): bool
    {
        $asignaciones = $this->asignacionesActivas($actor);

        if ($asignaciones->contains(fn ($asignacion): bool => in_array($asignacion->role_code, ['general_manager', 'admin'], true) && $asignacion->scope_type === 'GLOBAL')) {
            return true;
        }

        if ($asignaciones->contains(fn ($asignacion): bool => $asignacion->role_code === 'branch_manager' && $asignacion->branch_id === $branchId)) {
            return true;
        }

        return $actor->id === $coordinatorId
            && $asignaciones->contains(fn ($asignacion): bool => $asignacion->role_code === 'coordinator'
                && $asignacion->branch_id === $branchId);
    }

    private function actorPuedeOperar(User $actor, SolicitudDistribuidora $solicitud): bool
    {
        return $this->actorPuedeCrearEnSucursal($actor, $solicitud->branch_id, $solicitud->coordinator_id);
    }

    private function esCoordinadorDeSucursal(string $userId, string $branchId): bool
    {
        return UserRoleScope::query()
            ->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
            ->where('user_role_scopes.user_id', $userId)
            ->where('user_role_scopes.branch_id', $branchId)
            ->where('user_role_scopes.status', 'ACTIVE')
            ->whereNull('user_role_scopes.revoked_at')
            ->where('roles.code', 'coordinator')
            ->exists();
    }

    private function asignacionesActivas(User $actor)
    {
        return UserRoleScope::query()
            ->select(['roles.code as role_code', 'user_role_scopes.branch_id', 'user_role_scopes.scope_type'])
            ->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
            ->where('user_role_scopes.user_id', $actor->id)
            ->where('user_role_scopes.status', 'ACTIVE')
            ->whereNull('user_role_scopes.revoked_at')
            ->get();
    }

    /** @return array<string, string> */
    private function declaracionesIniciales(): array
    {
        return array_fill_keys([
            'personal_data', 'residence', 'partner', 'children', 'family_references',
            'vehicles', 'assets', 'liabilities', 'employment', 'commercial_credits',
        ], 'PENDING');
    }

    public function calcularCompletitud(SolicitudDistribuidora $app): array
    {
        $declaraciones = $this->obtenerDeclaraciones($app);

        return $this->validadorExpediente->calcularSeccionesCompletas($app, $declaraciones);
    }

    /** @return array<string, string> */
    public function calcularDeclaraciones(SolicitudDistribuidora $app): array
    {
        return $this->obtenerDeclaraciones($app);
    }

    /** @return array<string, string> */
    private function obtenerDeclaraciones(SolicitudDistribuidora $app): array
    {
        $cacheKey = $app->getKey().':'.$app->lock_version;

        return $this->declaracionesCache[$cacheKey]
            ??= $this->validadorExpediente->declaracionesAutomaticas($app);
    }
}
