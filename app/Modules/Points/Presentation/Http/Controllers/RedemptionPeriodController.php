<?php

declare(strict_types=1);

namespace App\Modules\Points\Presentation\Http\Controllers;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Configuration\Infrastructure\Persistence\Models\RedemptionPeriodModel;
use App\Modules\Points\Application\Services\PointsAccessService;
use App\Modules\Points\Application\Services\RedemptionPeriodService;
use App\Modules\Points\Domain\Enums\RedemptionPeriodStatus;
use App\Modules\Points\Domain\Exceptions\PointsDomainException;
use App\Modules\Points\Presentation\Http\Requests\CreateRedemptionPeriodRequest;
use App\Modules\Points\Presentation\Http\Requests\PublishRedemptionPeriodRequest;
use App\Modules\Points\Presentation\Http\Resources\RedemptionPeriodResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class RedemptionPeriodController extends Controller
{
    public function __construct(
        private readonly RedemptionPeriodService $periods,
        private readonly PointsAccessService $access,
    ) {}

    public function current(Request $request): JsonResponse|RedemptionPeriodResource
    {
        $actor = $this->actor($request);
        if (! $this->access->hasPermission($actor, PermissionCode::REDEMPTION_PERIOD_VIEW)) {
            throw new PointsDomainException('REDEMPTION_OUT_OF_SCOPE', 'No existe un periodo visible.', 404);
        }
        $period = $this->periods->current(CarbonImmutable::now('America/Monterrey'));

        return $period === null
            ? response()->json(['data' => null])
            : new RedemptionPeriodResource($period);
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        if (! $this->access->hasPermission($actor, PermissionCode::REDEMPTION_PERIOD_VIEW)) {
            throw new PointsDomainException('REDEMPTION_OUT_OF_SCOPE', 'No existen periodos visibles.', 404);
        }
        $query = RedemptionPeriodModel::query()->orderByDesc('starts_at')->orderByDesc('id');
        if (! in_array($actor->role_code, [RoleCode::GENERAL_MANAGER->value, RoleCode::ADMINISTRATOR->value], true)) {
            $query->where('status', RedemptionPeriodStatus::PUBLISHED->value);
        }

        return RedemptionPeriodResource::collection(
            $query->paginate(max(1, min(100, (int) $request->input('per_page', 20)))),
        )->response();
    }

    public function store(CreateRedemptionPeriodRequest $request): JsonResponse
    {
        $period = $this->periods->create(
            $this->actor($request),
            (string) $request->input('name'),
            $request->input('description'),
            CarbonImmutable::parse((string) $request->input('starts_at'), 'America/Monterrey'),
            CarbonImmutable::parse((string) $request->input('ends_at'), 'America/Monterrey'),
            $request->input('reason'),
        );

        return (new RedemptionPeriodResource($period))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $period): RedemptionPeriodResource
    {
        $actor = $this->actor($request);
        if (! $this->access->hasPermission($actor, PermissionCode::REDEMPTION_PERIOD_VIEW)) {
            throw new PointsDomainException('REDEMPTION_OUT_OF_SCOPE', 'El periodo no existe.', 404);
        }
        $model = RedemptionPeriodModel::query()->where('public_id', $period)->first();
        if ($model === null) {
            throw new PointsDomainException('REDEMPTION_PERIOD_INVALID', 'El periodo no existe.', 404);
        }
        if (! in_array($actor->role_code, [RoleCode::GENERAL_MANAGER->value, RoleCode::ADMINISTRATOR->value], true)
            && (string) $model->status !== RedemptionPeriodStatus::PUBLISHED->value) {
            throw new PointsDomainException('REDEMPTION_OUT_OF_SCOPE', 'El periodo no existe.', 404);
        }

        return new RedemptionPeriodResource($model);
    }

    public function publish(PublishRedemptionPeriodRequest $request, string $period): RedemptionPeriodResource
    {
        return new RedemptionPeriodResource($this->periods->publish(
            $this->actor($request),
            $period,
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
}
