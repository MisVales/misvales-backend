<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Presentation\Http\Controllers;

use App\Modules\Configuration\Application\Categories\CreateCategoryUseCase;
use App\Modules\Configuration\Application\Categories\DeactivateCategoryUseCase;
use App\Modules\Configuration\Application\DTOs\CreateCategoryData;
use App\Modules\Configuration\Application\DTOs\DeactivateCategoryData;
use App\Modules\Configuration\Domain\Enums\VersionStatus;
use App\Modules\Configuration\Infrastructure\Persistence\Models\CategoryModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\CategoryVersionModel;
use App\Modules\Configuration\Presentation\Http\Requests\CategoryListRequest;
use App\Modules\Configuration\Presentation\Http\Requests\CreateCategoryRequest;
use App\Modules\Configuration\Presentation\Http\Requests\DeactivateCategoryRequest;
use App\Modules\Configuration\Presentation\Http\Resources\CategoryVersionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class CategoryController extends Controller
{
    public function __construct(
        private readonly CreateCategoryUseCase $createUseCase,
        private readonly DeactivateCategoryUseCase $deactivateUseCase,
    ) {}

    public function index(CategoryListRequest $request): JsonResponse
    {
        $query = CategoryModel::query()
            ->with(['currentVersion'])
            ->whereHas('currentVersion');

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        } else {
            $query->where('status', '!=', VersionStatus::INACTIVE->value);
        }

        $perPage = (int) $request->input('per_page', 20);
        $paginator = $query->paginate($perPage);

        // Mapear usando la versión vigente
        $items = $paginator->getCollection()
            ->map(fn (CategoryModel $category): ?CategoryVersionModel => $category->currentVersion)
            ->filter(fn (?CategoryVersionModel $version): bool => $version !== null)
            ->values();
        $versions = new LengthAwarePaginator(
            $items,
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            $paginator->getOptions(),
        );

        return CategoryVersionResource::collection($versions)->response();
    }

    public function store(CreateCategoryRequest $request): JsonResponse
    {
        $version = $this->createUseCase->execute(
            new CreateCategoryData(
                name: $request->input('name'),
                description: $request->input('description'),
                distributorProfitRate: (string) $request->input('distributor_profit_rate'),
                actorUserId: $request->user()->id,
                idempotencyKey: $request->header('X-Idempotency-Key', (string) Str::uuid()),
            )
        );

        return (new CategoryVersionResource($version))
            ->response()
            ->setStatusCode(201);
    }

    public function deactivate(DeactivateCategoryRequest $request, string $publicId): JsonResponse
    {
        $this->deactivateUseCase->execute(
            new DeactivateCategoryData(
                categoryPublicId: $publicId,
                reason: $request->input('reason'),
                actorUserId: $request->user()->id,
                idempotencyKey: $request->header('X-Idempotency-Key', (string) Str::uuid()),
            )
        );

        return response()->json([], 204);
    }
}
