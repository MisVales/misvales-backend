<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Presentation\Http\Controllers;

use App\Modules\Configuration\Application\DTOs\CreateRedemptionPeriodData;
use App\Modules\Configuration\Application\DTOs\DeactivateRedemptionPeriodData;
use App\Modules\Configuration\Application\DTOs\EditRedemptionPeriodData;
use App\Modules\Configuration\Application\DTOs\PublishRedemptionPeriodData;
use App\Modules\Configuration\Application\RedemptionPeriods\CreateRedemptionPeriodUseCase;
use App\Modules\Configuration\Application\RedemptionPeriods\DeactivateRedemptionPeriodUseCase;
use App\Modules\Configuration\Application\RedemptionPeriods\EditRedemptionPeriodUseCase;
use App\Modules\Configuration\Application\RedemptionPeriods\PublishRedemptionPeriodUseCase;
use App\Modules\Configuration\Infrastructure\Persistence\Models\RedemptionPeriodModel;
use App\Modules\Configuration\Presentation\Http\Requests\CreateRedemptionPeriodRequest;
use App\Modules\Configuration\Presentation\Http\Requests\DeactivateRedemptionPeriodRequest;
use App\Modules\Configuration\Presentation\Http\Requests\EditRedemptionPeriodRequest;
use App\Modules\Configuration\Presentation\Http\Requests\PublishRedemptionPeriodRequest;
use App\Modules\Configuration\Presentation\Http\Requests\RedemptionPeriodListRequest;
use App\Modules\Configuration\Presentation\Http\Resources\RedemptionPeriodResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class RedemptionPeriodController extends Controller
{
    public function __construct(
        private readonly CreateRedemptionPeriodUseCase $createUseCase,
        private readonly EditRedemptionPeriodUseCase $editUseCase,
        private readonly PublishRedemptionPeriodUseCase $publishUseCase,
        private readonly DeactivateRedemptionPeriodUseCase $deactivateUseCase,
    ) {}

    public function index(RedemptionPeriodListRequest $request): JsonResponse
    {
        $query = RedemptionPeriodModel::query()->orderBy('starts_at', 'desc');

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = (int) $request->input('per_page', 20);
        $paginator = $query->paginate($perPage);

        return RedemptionPeriodResource::collection($paginator)->response();
    }

    public function store(CreateRedemptionPeriodRequest $request): JsonResponse
    {
        $period = $this->createUseCase->execute(
            new CreateRedemptionPeriodData(
                startsAt: CarbonImmutable::parse($request->input('starts_at')),
                endsAt: CarbonImmutable::parse($request->input('ends_at')),
                actorUserId: $request->user()->id,
                idempotencyKey: $request->header('X-Idempotency-Key', (string) Str::uuid()),
            )
        );

        return (new RedemptionPeriodResource($period))
            ->response()
            ->setStatusCode(201);
    }

    public function update(EditRedemptionPeriodRequest $request, string $publicId): JsonResponse
    {
        $period = $this->editUseCase->execute(
            new EditRedemptionPeriodData(
                periodPublicId: $publicId,
                startsAt: CarbonImmutable::parse($request->input('starts_at')),
                endsAt: CarbonImmutable::parse($request->input('ends_at')),
                lockVersion: (int) $request->input('lock_version'),
                actorUserId: $request->user()->id,
            )
        );

        return (new RedemptionPeriodResource($period))->response();
    }

    public function publish(PublishRedemptionPeriodRequest $request, string $publicId): JsonResponse
    {
        $period = $this->publishUseCase->execute(
            new PublishRedemptionPeriodData(
                periodPublicId: $publicId,
                reason: $request->input('reason'),
                actorUserId: $request->user()->id,
                idempotencyKey: $request->header('X-Idempotency-Key', (string) Str::uuid()),
            )
        );

        return (new RedemptionPeriodResource($period))->response();
    }

    public function deactivate(DeactivateRedemptionPeriodRequest $request, string $publicId): JsonResponse
    {
        $period = $this->deactivateUseCase->execute(
            new DeactivateRedemptionPeriodData(
                periodPublicId: $publicId,
                reason: $request->input('reason'),
                actorUserId: $request->user()->id,
                idempotencyKey: $request->header('X-Idempotency-Key', (string) Str::uuid()),
            )
        );

        return (new RedemptionPeriodResource($period))->response();
    }
}
