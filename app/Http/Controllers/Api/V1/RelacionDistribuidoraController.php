<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\Distribuidora;
use App\Models\RelacionDistribuidora;
use App\Services\Relacion\ServicioPdfEstadoCuenta;
use App\Services\Relacion\ServicioPdfRelacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RelacionDistribuidoraController extends Controller
{
    private const DETAIL_RELATIONS = [
        'distribuidora.usuario',
        'distribuidora.sucursal',
        'distribuidora.lineaCredito',
        'pagos.bankMovement:id,amount,applied_amount,surplus_amount,bank_folio',
        'pagos.asignaciones.partidaRelacion',
        'partidas',
        'puntosGanados:id,source_id,points',
    ];

    public function index(Request $request)
    {
        $query = RelacionDistribuidora::query()
            ->with(self::DETAIL_RELATIONS)
            ->latest('cutoff_at');
        $this->scope($query, $request);
        if ($request->filled('cutoff')) {
            $query->whereDate('cutoff_at', $request->date('cutoff'));
        }
        if ($request->filled('status')) {
            $query->where('financial_status', $request->string('status'));
        }
        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function (Builder $q) use ($search) {
                $q->where('payment_reference', 'like', "%{$search}%")
                    ->orWhereHas('distribuidora.usuario', function ($u) use ($search) {
                        $u->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orWhereHas('distribuidora', function ($d) use ($search) {
                        $d->where('distributor_number', 'like', "%{$search}%");
                    });
            });
        }

        return response()->json(['data' => $query->paginate($request->integer('per_page', 25))]);
    }

    public function show(RelacionDistribuidora $relacion, Request $request)
    {
        $this->authorizeView($relacion, $request);

        return response()->json(['data' => $relacion->load(self::DETAIL_RELATIONS)]);
    }

    public function download(RelacionDistribuidora $relacion, Request $request, ServicioPdfRelacion $pdf): Response
    {
        $this->authorizeView($relacion, $request);
        abort_unless($request->user()->hasPermissionTo('relations.download_own') || $request->user()->hasPermissionTo('relations.download_branch') || $request->user()->hasPermissionTo('relations.download_global'), 403);

        return response($pdf->generar($relacion), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="relacion-'.$relacion->payment_reference.'.pdf"',
        ]);
    }

    public function accountStatement(Distribuidora $distribuidora, Request $request, ServicioPdfEstadoCuenta $pdf): Response
    {
        $visible = RelacionDistribuidora::query()->where('distributor_id', $distribuidora->id);
        $this->scope($visible, $request);
        abort_unless($visible->exists(), 404);
        abort_unless($request->user()->hasPermissionTo('relations.download_own') || $request->user()->hasPermissionTo('relations.download_branch') || $request->user()->hasPermissionTo('relations.download_global'), 403);

        return response($pdf->generar($distribuidora), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="estado-de-cuenta-'.$distribuidora->distributor_number.'.pdf"',
        ]);
    }

    private function scope(Builder $query, Request $request): void
    {
        $user = $request->user();
        if ($user->hasPermissionTo('relations.view_global')) {
            return;
        }
        if ($user->hasPermissionTo('relations.view_branch')) {
            $query->whereIn('branch_id', $user->roleScopes()->where('status', 'ACTIVE')->whereNull('revoked_at')->where('scope_type', 'BRANCH')->select('branch_id'));

            return;
        }
        if ($user->hasPermissionTo('relations.view_assigned')) {
            $query->whereIn('distributor_id', CoordinatorDistributorAssignment::query()
                ->where('coordinator_id', $user->id)
                ->where('status', 'ACTIVE')
                ->whereNull('valid_to')
                ->select('distributor_id'));

            return;
        }
        if ($user->hasPermissionTo('relations.view_own') && $user->distribuidora) {
            $query->where('distributor_id', $user->distribuidora->id);

            return;
        }
        $query->whereRaw('1=0');
    }

    private function authorizeView(RelacionDistribuidora $relation, Request $request): void
    {
        $query = RelacionDistribuidora::query()->whereKey($relation->id);
        $this->scope($query, $request);
        abort_unless($query->exists(), 404);
    }
}
