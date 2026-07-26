<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Presentation\Http\Controllers;

use App\Modules\Configuration\Application\Categories\CreateCategoryVersionUseCase;
use App\Modules\Configuration\Application\Categories\EditCategoryVersionUseCase;
use App\Modules\Configuration\Application\Categories\PublishCategoryVersionUseCase;
use App\Modules\Configuration\Application\DTOs\CreateCategoryVersionData;
use App\Modules\Configuration\Application\DTOs\EditCategoryVersionData;
use App\Modules\Configuration\Application\DTOs\PublishCategoryVersionData;
use App\Modules\Configuration\Infrastructure\Persistence\Models\CategoryModel;
use App\Modules\Configuration\Presentation\Http\Requests\CreateCategoryVersionRequest;
use App\Modules\Configuration\Presentation\Http\Requests\EditCategoryVersionRequest;
use App\Modules\Configuration\Presentation\Http\Requests\PublishCategoryVersionRequest;
use App\Modules\Configuration\Presentation\Http\Resources\CategoryVersionResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class CategoryVersionController extends Controller
{
    public function __construct(
        private readonly CreateCategoryVersionUseCase $createUseCase,
        private readonly EditCategoryVersionUseCase $editUseCase,
        private readonly PublishCategoryVersionUseCase $publishUseCase,
    ) {}

    public function index(string $publicId): JsonResponse
    {
        $category = CategoryModel::query()->where('public_id', $publicId)->firstOrFail();

        $versions = $category->versions()->orderBy('version_number', 'desc')->paginate(20);

        return CategoryVersionResource::collection($versions)->response();
    }

    public function store(CreateCategoryVersionRequest $request, string $publicId): JsonResponse
    {
        $version = $this->createUseCase->execute(
            new CreateCategoryVersionData(
                categoryPublicId: $publicId,
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

    public function update(EditCategoryVersionRequest $request, string $categoryPublicId, string $versionPublicId): JsonResponse
    {
        $version = $this->editUseCase->execute(
            new EditCategoryVersionData(
                versionPublicId: $versionPublicId,
                name: $request->input('name'),
                description: $request->input('description'),
                distributorProfitRate: (string) $request->input('distributor_profit_rate'),
                lockVersion: (int) $request->input('lock_version'),
                actorUserId: $request->user()->id,
            )
        );

        return (new CategoryVersionResource($version))->response();
    }

    public function publish(PublishCategoryVersionRequest $request, string $categoryPublicId, string $versionPublicId): JsonResponse
    {
        $version = $this->publishUseCase->execute(
            new PublishCategoryVersionData(
                versionPublicId: $versionPublicId,
                effectiveFrom: CarbonImmutable::parse($request->input('effective_from')),
                reason: $request->input('reason'),
                actorUserId: $request->user()->id,
                idempotencyKey: $request->header('X-Idempotency-Key', (string) Str::uuid()),
            )
        );

        return (new CategoryVersionResource($version))->response();
    }
}
