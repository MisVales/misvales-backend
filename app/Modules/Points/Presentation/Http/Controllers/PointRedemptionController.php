<?php

declare(strict_types=1);

namespace App\Modules\Points\Presentation\Http\Controllers;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Points\Application\Queries\PointsQueryService;
use App\Modules\Points\Application\Services\DecidePointRedemption;
use App\Modules\Points\Application\Services\PointsAccessService;
use App\Modules\Points\Domain\Exceptions\PointsDomainException;
use App\Modules\Points\Infrastructure\Persistence\Models\PointRedemptionRequestModel;
use App\Modules\Points\Presentation\Http\Requests\PointDecisionRequest;
use App\Modules\Points\Presentation\Http\Requests\PointIndexRequest;
use App\Modules\Points\Presentation\Http\Resources\PointRedemptionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class PointRedemptionController extends Controller
{
    public function __construct(
        private readonly PointsQueryService $queries,
        private readonly PointsAccessService $access,
        private readonly DecidePointRedemption $decisions,
    ) {}

    public function mine(PointIndexRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        if ($actor->role_code !== RoleCode::DISTRIBUTOR->value) {
            throw new PointsDomainException('REDEMPTION_OUT_OF_SCOPE', 'La ruta solo muestra solicitudes propias.', 403);
        }
        $this->access->assertCanViewDistributor($actor, $actor);

        return PointRedemptionResource::collection(
            $this->queries->redemptionsForDistributor($actor, $request->validated(), (int) $request->input('per_page', 20)),
        )->response();
    }

    public function index(PointIndexRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        $query = PointRedemptionRequestModel::query()->with(['distributor', 'branchSnapshot', 'period']);
        $permission = match ($actor->role_code) {
            RoleCode::GENERAL_MANAGER->value,
            RoleCode::ADMINISTRATOR->value => PermissionCode::POINTS_VIEW_GLOBAL,
            RoleCode::SUCURSAL_MANAGER->value => PermissionCode::POINTS_VIEW_BRANCH,
            RoleCode::COORDINATOR->value => PermissionCode::POINTS_VIEW_ASSIGNED,
            default => throw new PointsDomainException('REDEMPTION_OUT_OF_SCOPE', 'No existe una bandeja para este rol.', 403),
        };
        if (! $this->access->hasPermission($actor, $permission)) {
            throw new PointsDomainException('REDEMPTION_OUT_OF_SCOPE', 'No existe una bandeja para este rol.', 403);
        }
        if ($actor->role_code === RoleCode::SUCURSAL_MANAGER->value) {
            $query->where('branch_id_snapshot', $actor->branch_id);
        }
        if ($actor->role_code === RoleCode::COORDINATOR->value) {
            $query->whereIn(
                'distributor_id',
                User::query()->where('coordinator_id', $actor->id)->select('id'),
            );
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return PointRedemptionResource::collection(
            $query->orderByDesc('requested_at')->orderByDesc('id')
                ->paginate((int) $request->input('per_page', 20)),
        )->response();
    }

    public function show(Request $request, string $redemption): PointRedemptionResource
    {
        $model = $this->model($redemption);
        $distributor = User::query()->with('role')->findOrFail($model->distributor_id);
        $this->access->assertCanViewDistributor($this->actor($request), $distributor);

        return new PointRedemptionResource($model);
    }

    public function authorize(PointDecisionRequest $request, string $redemption): PointRedemptionResource
    {
        return new PointRedemptionResource($this->decisions->authorize(
            $this->actor($request),
            $redemption,
            (string) $request->input('reauthentication_token'),
            (string) $request->header('Idempotency-Key', ''),
            $request->input('reason'),
        ));
    }

    public function reject(PointDecisionRequest $request, string $redemption): PointRedemptionResource
    {
        return new PointRedemptionResource($this->decisions->reject(
            $this->actor($request),
            $redemption,
            (string) $request->input('reauthentication_token'),
            (string) $request->header('Idempotency-Key', ''),
            $request->input('reason'),
        ));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new PointsDomainException('AUTHENTICATION_REQUIRED', 'La operación requiere sesión.', 401);
        }
        $actor->loadMissing('role');

        return $actor;
    }

    private function model(string $id): PointRedemptionRequestModel
    {
        $model = PointRedemptionRequestModel::query()
            ->with(['distributor', 'branchSnapshot', 'period'])
            ->where('id', $id)
            ->orWhere('public_folio', $id)
            ->first();
        if ($model === null) {
            throw new PointsDomainException('REDEMPTION_OUT_OF_SCOPE', 'La solicitud no existe.', 404);
        }

        return $model;
    }
}
