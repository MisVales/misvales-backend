<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Presentation\Http\Controllers;

use App\Modules\Configuration\Application\DTOs\CreateProductData;
use App\Modules\Configuration\Application\DTOs\DeactivateProductData;
use App\Modules\Configuration\Application\Products\CreateProductUseCase;
use App\Modules\Configuration\Application\Products\DeactivateProductUseCase;
use App\Modules\Configuration\Domain\Enums\VersionStatus;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ProductModel;
use App\Modules\Configuration\Presentation\Http\Requests\CreateProductRequest;
use App\Modules\Configuration\Presentation\Http\Requests\DeactivateProductRequest;
use App\Modules\Configuration\Presentation\Http\Requests\ProductListRequest;
use App\Modules\Configuration\Presentation\Http\Resources\ProductVersionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class ProductController extends Controller
{
    public function __construct(
        private readonly CreateProductUseCase $createUseCase,
        private readonly DeactivateProductUseCase $deactivateUseCase,
    ) {}

    public function index(ProductListRequest $request): JsonResponse
    {
        $query = ProductModel::query()
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
        $items = $paginator->getCollection()->map(fn ($prod) => $prod->currentVersion);
        
        $paginator->setCollection($items);

        return ProductVersionResource::collection($paginator)->response();
    }

    public function store(CreateProductRequest $request): JsonResponse
    {
        $version = $this->createUseCase->execute(
            new CreateProductData(
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

    public function deactivate(DeactivateProductRequest $request, string $publicId): JsonResponse
    {
        $this->deactivateUseCase->execute(
            new DeactivateProductData(
                productPublicId: $publicId,
                reason: $request->input('reason'),
                actorUserId: $request->user()->id,
                idempotencyKey: $request->header('X-Idempotency-Key', (string) Str::uuid()),
            )
        );

        return response()->json([], 204);
    }
}
