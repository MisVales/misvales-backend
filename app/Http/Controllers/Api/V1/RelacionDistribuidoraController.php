<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\RelacionDistribuidora;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RelacionDistribuidoraController extends Controller
{
    public function index(Request $request)
    {
        $query = RelacionDistribuidora::query()->with(['distribuidora.usuario', 'partidas'])->latest('cutoff_at');
        $this->scope($query, $request);
        if ($request->filled('cutoff')) {
            $query->whereDate('cutoff_at', $request->date('cutoff'));
        }
        if ($request->filled('status')) {
            $query->where('financial_status', $request->string('status'));
        }

        return response()->json(['data' => $query->paginate(25)]);
    }

    public function show(RelacionDistribuidora $relacion, Request $request)
    {
        $this->authorizeView($relacion, $request);

        return response()->json(['data' => $relacion->load(['partidas', 'distribuidora.usuario', 'pagos'])]);
    }

    public function download(RelacionDistribuidora $relacion, Request $request): Response
    {
        $this->authorizeView($relacion, $request);
        abort_unless($request->user()->hasPermissionTo('relations.download_own') || $request->user()->hasPermissionTo('relations.download_branch') || $request->user()->hasPermissionTo('relations.download_global'), 403);
        $relacion->load('partidas');
        $rows = $relacion->partidas->map(fn ($item) => '<tr><td>'.e($item->snapshot['folio']).'</td><td>'.e($item->snapshot['installment']).'</td><td>'.e($item->portfolio_amount).'</td><td>'.e($item->misvales_amount).'</td></tr>')->implode('');
        $html = '<!doctype html><meta charset="utf-8"><h1>Relación '.e($relacion->payment_reference).'</h1><p>Distribuidora: '.e($relacion->header_snapshot['name'] ?? '').'</p><p>Fecha límite: '.e($relacion->payment_deadline_at->toIso8601String()).'</p><p>Total a pagar: '.e($relacion->balance).'</p><table><thead><tr><th>Folio</th><th>Parcialidad</th><th>Total cliente</th><th>Exigible MisVales</th></tr></thead><tbody>'.$rows.'</tbody></table><p>Banco: '.e($relacion->bank_snapshot['name']).' · Beneficiario: '.e($relacion->bank_snapshot['beneficiary']).' · CLABE: '.e($relacion->bank_snapshot['clabe']).'</p>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="relacion-'.$relacion->payment_reference.'.html"']);
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
