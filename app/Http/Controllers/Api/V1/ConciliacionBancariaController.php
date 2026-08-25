<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ExcepcionConciliacion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Conciliacion\CrearAclaracionPagoRequest;
use App\Http\Requests\Api\V1\Conciliacion\CrearTransferenciaBancariaSimuladaRequest;
use App\Http\Requests\Api\V1\Conciliacion\DecidirConciliacionManualRequest;
use App\Http\Requests\Api\V1\Conciliacion\ImportarArchivoBancarioRequest;
use App\Http\Requests\Api\V1\Conciliacion\ListarFlujoConciliacionRequest;
use App\Http\Requests\Api\V1\Conciliacion\ListarMovimientosBancariosRequest;
use App\Http\Requests\Api\V1\Conciliacion\SolicitarConciliacionManualRequest;
use App\Http\Resources\Api\V1\Conciliacion\AclaracionPagoResource;
use App\Http\Resources\Api\V1\Conciliacion\ImportacionBancariaResource;
use App\Http\Resources\Api\V1\Conciliacion\MovimientoBancarioResource;
use App\Http\Resources\Api\V1\Conciliacion\SolicitudConciliacionManualResource;
use App\Models\AclaracionPago;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\ImportacionArchivoBancario;
use App\Models\MovimientoBancario;
use App\Models\RelacionDistribuidora;
use App\Models\SolicitudConciliacionManual;
use App\Models\TransferenciaBancariaSimulada;
use App\Models\User;
use App\Services\Conciliacion\ServicioConciliacionManual;
use App\Services\Conciliacion\ServicioDisponibilidadConciliacion;
use App\Services\Conciliacion\ServicioImportacionBancaria;
use App\Services\Conciliacion\ServicioTicketPagoSimulado;
use App\Services\Conciliacion\ServicioTransferenciasBancariasSimuladas;
use App\Services\Operaciones\ServicioFinPeriodoPagoManual;
use App\Services\Riesgo\ServicioMorosidadDistribuidora;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

final class ConciliacionBancariaController extends Controller
{
    public function pendingPeriods(Request $request, ServicioDisponibilidadConciliacion $availability): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermissionTo('bank_imports.create_branch') || $user->hasPermissionTo('bank_imports.view_global'), 403);

        return response()->json(['data' => $availability->periodosPendientes(
            $user->hasPermissionTo('bank_imports.view_global') ? null : $this->cashierBranchId($user),
        )]);
    }

    public function import(ImportarArchivoBancarioRequest $request, ServicioImportacionBancaria $service, ServicioDisponibilidadConciliacion $availability, ServicioFinPeriodoPagoManual $paymentPeriod, ServicioMorosidadDistribuidora $risk)
    {
        $branchId = $request->user()->branch_id;
        if (! $request->user()->hasRole('cashier') || $branchId === null || ! $request->user()->hasScopeForBranch($branchId)) {
            throw new ExcepcionConciliacion('BANK_IMPORT_SCOPE_DENIED', 'La cajera no tiene una sucursal operativa autorizada.', 403);
        }
        $processRunId = $availability->asegurarCorteVencido($request->validated('process_run_id'), $branchId);

        $import = $service->importar($request->file('file'), $request->user(), $branchId, $processRunId);
        $risk->evaluarCorteConciliado($processRunId, $branchId);
        $paymentPeriod->forzar($request->user(), 'Cierre automático después de procesar el archivo bancario final', $processRunId);

        return (new ImportacionBancariaResource($import))
            ->response()
            ->setStatusCode($import->replayed ? 200 : 201);
    }

    public function imports(Request $request)
    {
        $user = $request->user();
        $global = $user->hasPermissionTo('bank_imports.view_global');
        if (! $global && ! $user->hasPermissionTo('bank_imports.view_branch')) {
            throw new ExcepcionConciliacion('BANK_IMPORT_VIEW_DENIED', 'No tienes permiso para consultar importaciones bancarias.', 403);
        }

        $query = ImportacionArchivoBancario::query()->latest();
        if (! $global) {
            $query->whereIn('branch_id', $this->branchIds($user));
        }

        return ImportacionBancariaResource::collection($query->paginate(25));
    }

    public function simulate(CrearTransferenciaBancariaSimuladaRequest $request, ServicioTransferenciasBancariasSimuladas $service): JsonResponse
    {
        $relation = RelacionDistribuidora::query()->whereKey($request->validated('relation_id'))->firstOrFail();
        $user = $request->user();
        if ($user->hasPermissionTo('bank_imports.create_branch')) {
            $branchId = $this->cashierBranchId($user);
        } elseif ($user->hasPermissionTo('relations.view_own') && $user->distribuidora?->id === $relation->distributor_id) {
            $branchId = $relation->branch_id;
        } else {
            throw new ExcepcionConciliacion('BANK_SIMULATION_SCOPE_DENIED', 'No tienes autorización para simular una transferencia de esta relación.', 403);
        }

        $transfer = $service->registrar($request->validated(), $request->user(), $branchId);

        return response()->json(['data' => $transfer], 201);
    }

    public function simulations(Request $request, ServicioTransferenciasBancariasSimuladas $service, ServicioDisponibilidadConciliacion $availability): JsonResponse
    {
        $branchId = $this->cashierBranchId($request->user());
        $processRunId = $availability->asegurarCorteVencido($request->query('process_run_id'), $branchId);

        return response()->json(['data' => $service->listar($branchId, $processRunId)]);
    }

    public function exportSimulations(Request $request, ServicioTransferenciasBancariasSimuladas $service, ServicioDisponibilidadConciliacion $availability): BinaryFileResponse
    {
        $branchId = $this->cashierBranchId($request->user());
        $processRunId = $availability->asegurarCorteVencido($request->query('process_run_id'), $branchId);
        $path = $service->exportar($branchId, $processRunId);

        return response()->download(
            $path,
            'movimientos-bancarios-simulados-'.now()->format('Ymd-His').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        )->deleteFileAfterSend(true);
    }

    public function downloadSimulationTicket(TransferenciaBancariaSimulada $transferencia, Request $request, ServicioTicketPagoSimulado $ticket): Response
    {
        $user = $request->user();
        $ownsTransfer = $transferencia->created_by === $user->id;
        $cashierCanPrint = $user->hasRole('cashier')
            && $user->hasPermissionTo('bank_imports.view_branch')
            && $user->hasScopeForBranch($transferencia->branch_id);
        abort_unless($ownsTransfer || $cashierCanPrint, 403);

        return response($ticket->generar($transferencia), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="ticket-'.$transferencia->bank_folio.'.pdf"',
        ]);
    }

    public function movements(ListarMovimientosBancariosRequest $request)
    {
        $data = $request->validated();
        $query = MovimientoBancario::query()
            ->with(['relation', 'distributor', 'manualRequest'])
            ->latest();
        if (! $request->user()->hasPermissionTo('bank_movements.view_global')) {
            $query->whereHas('import', fn (Builder $import) => $import->whereIn('branch_id', $this->branchIds($request->user())));
        }
        $query
            ->when($data['result'] ?? null, fn (Builder $builder, string $result) => $builder->where('classification', $result))
            ->when($data['status'] ?? null, fn (Builder $builder, string $status) => $builder->where('reconciliation_status', $status))
            ->when($data['search'] ?? null, function (Builder $builder, string $search): void {
                $builder->where(function (Builder $nested) use ($search): void {
                    $nested->where('bank_folio', 'like', "%{$search}%")
                        ->orWhere('payment_reference', 'like', "%{$search}%")
                        ->orWhere('concept', 'like', "%{$search}%");
                });
            });

        return MovimientoBancarioResource::collection($query->paginate($data['per_page'] ?? 50));
    }

    public function clarifications(ListarFlujoConciliacionRequest $request)
    {
        $query = AclaracionPago::query()->with(['relation', 'distributor.usuario'])->latest();
        $this->scopeClarifications($query, $request->user());
        $query->when($request->validated('status'), fn (Builder $builder, string $status) => $builder->where('status', $status));

        return AclaracionPagoResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function manualRequests(ListarFlujoConciliacionRequest $request)
    {
        $query = SolicitudConciliacionManual::query()
            ->with(['movement', 'relation.distribuidora.usuario', 'clarification', 'requester', 'authorizer', 'executor'])
            ->latest();
        $this->scopeManualRequests($query, $request->user());
        $query->when($request->validated('status'), fn (Builder $builder, string $status) => $builder->where('status', $status));

        return SolicitudConciliacionManualResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function clarify(RelacionDistribuidora $relacion, CrearAclaracionPagoRequest $request, ServicioConciliacionManual $service)
    {
        return (new AclaracionPagoResource($service->aclarar($relacion, $request->validated(), $request->user())))
            ->response()
            ->setStatusCode(201);
    }

    public function requestManual(MovimientoBancario $movimiento, SolicitarConciliacionManualRequest $request, ServicioConciliacionManual $service)
    {
        return (new SolicitudConciliacionManualResource($service->solicitar($movimiento, $request->validated(), $request->user())))
            ->response()
            ->setStatusCode(201);
    }

    public function decideManual(SolicitudConciliacionManual $solicitud, DecidirConciliacionManualRequest $request, ServicioConciliacionManual $service)
    {
        return new SolicitudConciliacionManualResource($service->decidir($solicitud, $request->validated(), $request->user()));
    }

    public function executeManual(SolicitudConciliacionManual $solicitud, Request $request, ServicioConciliacionManual $service)
    {
        return new SolicitudConciliacionManualResource($service->ejecutar($solicitud, $request->user()));
    }

    private function scopeClarifications(Builder $query, User $user): void
    {
        if ($user->hasPermissionTo('payment_clarifications.view_global')) {
            return;
        }
        if ($user->hasPermissionTo('payment_clarifications.view_branch')) {
            $query->whereHas('relation', fn (Builder $relation) => $relation->whereIn('branch_id', $this->branchIds($user)));

            return;
        }
        if ($user->hasPermissionTo('payment_clarifications.view_assigned')) {
            $query->whereIn('distributor_id', $this->assignedDistributorIds($user));

            return;
        }
        if ($user->hasPermissionTo('payment_clarifications.view_own') && $user->distribuidora !== null) {
            $query->where('distributor_id', $user->distribuidora->id);

            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function scopeManualRequests(Builder $query, User $user): void
    {
        if ($user->hasPermissionTo('manual_reconciliation.view_global')) {
            return;
        }
        if ($user->hasPermissionTo('manual_reconciliation.view_branch')) {
            $query->whereIn('branch_id', $this->branchIds($user));

            return;
        }
        if ($user->hasPermissionTo('manual_reconciliation.view_assigned')) {
            $query->whereHas('relation', fn (Builder $relation) => $relation->whereIn('distributor_id', $this->assignedDistributorIds($user)));

            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function branchIds(User $user)
    {
        return $user->roleScopes()
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->where('scope_type', 'BRANCH')
            ->select('branch_id');
    }

    private function assignedDistributorIds(User $user)
    {
        return CoordinatorDistributorAssignment::query()
            ->where('coordinator_id', $user->id)
            ->where('status', 'ACTIVE')
            ->whereNull('valid_to')
            ->select('distributor_id');
    }

    private function cashierBranchId(User $user): string
    {
        $branchId = $user->branch_id;
        if (! $user->hasRole('cashier') || $branchId === null || ! $user->hasScopeForBranch($branchId)) {
            throw new ExcepcionConciliacion('BANK_SIMULATION_SCOPE_DENIED', 'La cajera no tiene una sucursal operativa autorizada.', 403);
        }

        return $branchId;
    }
}
