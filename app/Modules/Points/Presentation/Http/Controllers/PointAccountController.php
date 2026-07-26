<?php

declare(strict_types=1);

namespace App\Modules\Points\Presentation\Http\Controllers;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Points\Application\Queries\PointsQueryService;
use App\Modules\Points\Application\Services\PointsAccessService;
use App\Modules\Points\Domain\Exceptions\PointsDomainException;
use App\Modules\Points\Infrastructure\Persistence\Models\RelationPointEvaluationModel;
use App\Modules\Points\Presentation\Http\Requests\PointIndexRequest;
use App\Modules\Points\Presentation\Http\Resources\PointBalanceResource;
use App\Modules\Points\Presentation\Http\Resources\PointMovementResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class PointAccountController extends Controller
{
    public function __construct(
        private readonly PointsQueryService $queries,
        private readonly PointsAccessService $access,
    ) {}

    public function me(Request $request): PointBalanceResource
    {
        $distributor = $this->actor($request);
        if ($distributor->role_code !== RoleCode::DISTRIBUTOR->value) {
            throw new PointsDomainException('REDEMPTION_OUT_OF_SCOPE', 'La ruta solo expone la cuenta propia.', 403);
        }
        $this->access->assertCanViewDistributor($distributor, $distributor);

        return new PointBalanceResource($this->queries->balance($distributor));
    }

    public function show(Request $request, string $distributor): PointBalanceResource
    {
        $target = $this->distributor($distributor);
        $this->access->assertCanViewDistributor($this->actor($request), $target);

        return new PointBalanceResource($this->queries->balance($target));
    }

    public function myMovements(PointIndexRequest $request): JsonResponse
    {
        $distributor = $this->actor($request);
        if ($distributor->role_code !== RoleCode::DISTRIBUTOR->value) {
            throw new PointsDomainException('REDEMPTION_OUT_OF_SCOPE', 'La ruta solo expone movimientos propios.', 403);
        }
        $this->access->assertCanViewDistributor($distributor, $distributor);

        return PointMovementResource::collection(
            $this->queries->movements($distributor, $request->validated(), (int) $request->input('per_page', 20)),
        )->response();
    }

    public function movements(PointIndexRequest $request, string $distributor): JsonResponse
    {
        $target = $this->distributor($distributor);
        $this->access->assertCanViewDistributor($this->actor($request), $target);

        return PointMovementResource::collection(
            $this->queries->movements($target, $request->validated(), (int) $request->input('per_page', 20)),
        )->response();
    }

    public function relation(Request $request, string $relation): JsonResponse
    {
        $evaluation = RelationPointEvaluationModel::query()->where('relation_id', $relation)->first();
        if ($evaluation === null) {
            throw new PointsDomainException('POINT_ACCOUNT_NOT_FOUND', 'La relación no tiene evaluación visible.', 404);
        }
        $target = User::query()->with('role')->findOrFail($evaluation->distributor_id);
        $this->access->assertCanViewDistributor($this->actor($request), $target);
        $result = $this->queries->relation($relation);

        return response()->json([
            'data' => [
                'evaluation' => [
                    'id' => $result['evaluation']->id,
                    'relation_id' => $result['evaluation']->relation_id,
                    'classification' => $result['evaluation']->classification->value,
                    'products_capital_basis' => $result['evaluation']->products_capital_basis,
                    'points_earned' => (int) $result['evaluation']->points_earned,
                    'points_penalized' => (int) $result['evaluation']->points_penalized,
                    'balance_before' => (int) $result['evaluation']->balance_before,
                    'balance_after' => (int) $result['evaluation']->balance_after,
                    'result' => $result['evaluation']->result->value,
                    'processed_at' => $result['evaluation']->processed_at->toIso8601String(),
                ],
                'movements' => PointMovementResource::collection(collect($result['movements']))->resolve($request),
            ],
        ]);
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

    private function distributor(string $publicId): User
    {
        $distributor = User::query()->with('role')->where('public_id', $publicId)->first();
        if ($distributor === null || $distributor->role_code !== RoleCode::DISTRIBUTOR->value) {
            throw new PointsDomainException('POINT_ACCOUNT_NOT_FOUND', 'La cuenta no existe.', 404);
        }

        return $distributor;
    }
}
