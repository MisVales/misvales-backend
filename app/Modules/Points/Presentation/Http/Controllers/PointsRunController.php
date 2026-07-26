<?php

declare(strict_types=1);

namespace App\Modules\Points\Presentation\Http\Controllers;

use App\Models\User;
use App\Modules\Points\Application\Services\PointsAccessService;
use App\Modules\Points\Domain\Exceptions\PointsDomainException;
use App\Modules\Points\Infrastructure\Persistence\Models\PointsRunModel;
use App\Modules\Points\Presentation\Http\Resources\PointsRunResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

final class PointsRunController extends Controller
{
    public function __construct(private readonly PointsAccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $this->assertGlobalRead($request);

        return PointsRunResource::collection(
            PointsRunModel::query()
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(max(1, min(100, (int) $request->input('per_page', 20)))),
        )->response();
    }

    public function show(Request $request, string $run): JsonResponse
    {
        $this->assertGlobalRead($request);
        $model = PointsRunModel::query()->where('id', $run)->orWhere('public_folio', $run)->first();
        if ($model === null) {
            throw new PointsDomainException('POINT_ACCOUNT_NOT_FOUND', 'La ejecución no existe.', 404);
        }

        return (new PointsRunResource($model))->response();
    }

    public function items(Request $request, string $run): JsonResponse
    {
        $this->assertGlobalRead($request);

        return response()->json(DB::table('points_run_items')
            ->where('points_run_id', $run)
            ->orderBy('processed_at')
            ->orderBy('id')
            ->paginate(max(1, min(100, (int) $request->input('per_page', 20)))));
    }

    private function assertGlobalRead(Request $request): void
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new PointsDomainException('AUTHENTICATION_REQUIRED', 'La operación requiere sesión.', 401);
        }
        $actor->loadMissing('role');
        $this->access->assertCanViewRuns($actor);
    }
}
