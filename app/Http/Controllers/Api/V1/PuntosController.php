<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Distribuidora;
use App\Models\PointRedemptionRequest;
use App\Services\Puntos\ServicioCanjePuntos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PuntosController extends Controller
{
    public function __construct(
        private readonly ServicioCanjePuntos $servicioPuntos
    ) {}

    public function balance(Request $request)
    {
        $user = $request->user();
        abort_unless($this->hasAnyViewPermission($user), 403);
        $distributorId = $request->query('distributor_id');

        if ($distributorId) {
            $distributor = Distribuidora::with('usuario')->findOrFail($distributorId);
            $this->authorizeViewDistributor($distributor, $request);
        } else {
            $distributor = $user->distribuidora;
            if (! $distributor) {
                // If not a distributor, return general point value and empty account
                return response()->json([
                    'data' => [
                        'point_value' => $this->servicioPuntos->obtenerValorPuntoVigente(),
                        'balance' => 0,
                        'reserved' => 0,
                        'available_points' => 0,
                        'money_equivalent' => '0.0000',
                        'total_money_equivalent' => '0.0000',
                    ],
                ]);
            }
        }

        return response()->json([
            'data' => $this->servicioPuntos->consultarResumen($distributor),
        ]);
    }

    public function redemptions(Request $request)
    {
        abort_unless($this->hasAnyViewPermission($request->user()), 403);
        $query = PointRedemptionRequest::query()
            ->with(['distribuidora.usuario', 'distribuidora.sucursal', 'solicitante', 'autorizador', 'entregador'])
            ->latest('requested_at');

        $this->scopeRedemptions($query, $request);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function (Builder $q) use ($search) {
                $q->whereHas('distribuidora.usuario', function ($u) use ($search) {
                    $u->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })->orWhereHas('distribuidora', function ($d) use ($search) {
                    $d->where('distributor_number', 'like', "%{$search}%");
                });
            });
        }

        return response()->json([
            'data' => $query->paginate($request->integer('per_page', 25)),
        ]);
    }

    public function show(PointRedemptionRequest $redemption, Request $request)
    {
        $this->authorizeViewRedemption($redemption, $request);

        return response()->json([
            'data' => $redemption->load(['distribuidora.usuario', 'distribuidora.sucursal', 'solicitante', 'autorizador', 'entregador']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'points' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'distributor_id' => ['sometimes', 'nullable', 'uuid', 'exists:distributors,id'],
        ]);

        $user = $request->user();
        abort_unless($user->hasPermissionTo('points.request_own'), 403);

        $distributor = $user->distribuidora;
        abort_unless($distributor, 404, 'DISTRIBUTOR_NOT_FOUND');
        if (! empty($validated['distributor_id'])) {
            abort_unless($validated['distributor_id'] === $distributor->id, 404);
        }

        $redemption = $this->servicioPuntos->solicitarCanje($distributor, $validated['points'], $user);

        return response()->json(['data' => $redemption], 201);
    }

    public function authorizeRequest(PointRedemptionRequest $redemption, Request $request)
    {
        $user = $request->user();
        $global = $user->hasPermissionTo('points.authorize_global');
        $branch = $user->hasPermissionTo('points.authorize_branch') && $user->hasScopeForBranch($redemption->distribuidora->branch_id);

        abort_unless($global || $branch, 403);

        $authorized = $this->servicioPuntos->autorizarCanje($redemption, $user);

        return response()->json(['data' => $authorized]);
    }

    public function rejectRequest(PointRedemptionRequest $redemption, Request $request)
    {
        $user = $request->user();
        $global = $user->hasPermissionTo('points.authorize_global');
        $branch = $user->hasPermissionTo('points.authorize_branch') && $user->hasScopeForBranch($redemption->distribuidora->branch_id);

        abort_unless($global || $branch, 403);

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:500'],
        ]);

        $rejected = $this->servicioPuntos->rechazarCanje($redemption, $user, $validated['rejection_reason']);

        return response()->json(['data' => $rejected]);
    }

    public function deliverRequest(PointRedemptionRequest $redemption, Request $request)
    {
        $user = $request->user();
        $global = $user->hasPermissionTo('points.authorize_global');
        $deliver = $user->hasPermissionTo('points.deliver_branch') && $user->hasScopeForBranch($redemption->distribuidora->branch_id);

        abort_unless($global || $deliver, 403);

        $validated = $request->validate([
            'delivery_notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $delivered = $this->servicioPuntos->entregarCanje($redemption, $user, $validated['delivery_notes'] ?? null);

        return response()->json(['data' => $delivered]);
    }

    private function scopeRedemptions(Builder $query, Request $request): void
    {
        $user = $request->user();
        if ($user->hasPermissionTo('points.view_global')) {
            return;
        }

        if ($user->hasPermissionTo('points.view_branch')) {
            $branches = $user->roleScopes()->where('status', 'ACTIVE')->whereNull('revoked_at')->where('scope_type', 'BRANCH')->select('branch_id');
            $query->whereHas('distribuidora', fn ($q) => $q->whereIn('branch_id', $branches));

            return;
        }

        if ($user->hasPermissionTo('points.view_own') && $user->distribuidora) {
            $query->where('distributor_id', $user->distribuidora->id);

            return;
        }

        $query->whereRaw('1=0');
    }

    private function authorizeViewDistributor(Distribuidora $distributor, Request $request): void
    {
        $user = $request->user();
        if ($user->hasPermissionTo('points.view_global')) {
            return;
        }
        if ($user->hasPermissionTo('points.view_branch') && $user->hasScopeForBranch($distributor->branch_id)) {
            return;
        }
        if ($user->hasPermissionTo('points.view_own') && $user->distribuidora && $user->distribuidora->id === $distributor->id) {
            return;
        }
        abort(403);
    }

    private function hasAnyViewPermission(User $user): bool
    {
        return collect(['points.view_own', 'points.view_branch', 'points.view_global'])
            ->contains(fn (string $permission): bool => $user->hasPermissionTo($permission));
    }

    private function authorizeViewRedemption(PointRedemptionRequest $redemption, Request $request): void
    {
        $query = PointRedemptionRequest::query()->whereKey($redemption->id);
        $this->scopeRedemptions($query, $request);
        abort_unless($query->exists(), 404);
    }
}
