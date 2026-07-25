<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Presentation\Http\Controllers;

use App\Modules\Configuration\Application\DTOs\CreateProductVersionData;
use App\Modules\Configuration\Application\DTOs\EditProductVersionData;
use App\Modules\Configuration\Application\DTOs\PublishProductVersionData;
use App\Modules\Configuration\Application\Products\CreateProductVersionUseCase;
use App\Modules\Configuration\Application\Products\EditProductVersionUseCase;
use App\Modules\Configuration\Application\Products\PublishProductVersionUseCase;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ProductModel;
use App\Modules\Configuration\Presentation\Http\Requests\CreateProductVersionRequest;
use App\Modules\Configuration\Presentation\Http\Requests\EditProductVersionRequest;
use App\Modules\Configuration\Presentation\Http\Requests\PublishProductVersionRequest;
use App\Modules\Configuration\Presentation\Http\Resources\ProductVersionResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class ProductVersionController extends Controller
{
    public function __construct(
        private readonly CreateProductVersionUseCase $createUseCase,
        private readonly EditProductVersionUseCase $editUseCase,
        private readonly PublishProductVersionUseCase $publishUseCase,
    ) {}

    public function index(string $publicId): JsonResponse
    {
        $product = ProductModel::query()->where('public_id', $publicId)->firstOrFail();
        
        $versions = $product->versions()->orderBy('version_number', 'desc')->paginate(20);

        return ProductVersionResource::collection($versions)->response();
    }

    public function store(CreateProductVersionRequest $request, string $publicId): JsonResponse
    {
        $version = $this->createUseCase->execute(
            new CreateProductVersionData(
                productPublicId: $publicId,
                amount: (string) $request->input('amount'),
                loanCommissionRate: (string) $request->input('loan_commission_rate'),
                interestRatePerFortnight: (string) $request->input('interest_rate_per_fortnight'),
                insuranceAmount: (string) $request->input('insurance_amount'),
                fortnightCount: (int) $request->input('fortnight_count'),
                actorUserId: $request->user()->id,
                idempotencyKey: $request->header('X-Idempotency-Key', (string) Str::uuid()),
            )
        );

        return (new ProductVersionResource($version))
            ->response()
            ->setStatusCode(201);
    }

    public function update(EditProductVersionRequest $request, string $productPublicId, string $versionPublicId): JsonResponse
    {
        $version = $this->editUseCase->execute(
            new EditProductVersionData(
                versionPublicId: $versionPublicId,
                amount: (string) $request->input('amount'),
                loanCommissionRate: (string) $request->input('loan_commission_rate'),
                interestRatePerFortnight: (string) $request->input('interest_rate_per_fortnight'),
                insuranceAmount: (string) $request->input('insurance_amount'),
                fortnightCount: (int) $request->input('fortnight_count'),
                lockVersion: (int) $request->input('lock_version'),
                actorUserId: $request->user()->id,
            )
        );

        return (new ProductVersionResource($version))->response();
    }

    public function publish(PublishProductVersionRequest $request, string $productPublicId, string $versionPublicId): JsonResponse
    {
        $version = $this->publishUseCase->execute(
            new PublishProductVersionData(
                versionPublicId: $versionPublicId,
                effectiveFrom: CarbonImmutable::parse($request->input('effective_from')),
                reason: $request->input('reason'),
                actorUserId: $request->user()->id,
                idempotencyKey: $request->header('X-Idempotency-Key', (string) Str::uuid()),
            )
        );

        return (new ProductVersionResource($version))->response();
    }
}
