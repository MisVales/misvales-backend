<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Presentation\Http\Controllers;

use App\Modules\Configuration\Application\Configurations\CreateConfigurationVersionUseCase;
use App\Modules\Configuration\Application\Configurations\DeactivateConfigurationVersionUseCase;
use App\Modules\Configuration\Application\Configurations\EditConfigurationVersionUseCase;
use App\Modules\Configuration\Application\Configurations\PublishConfigurationVersionUseCase;
use App\Modules\Configuration\Application\DTOs\CreateConfigurationVersionData;
use App\Modules\Configuration\Application\DTOs\DeactivateConfigurationVersionData;
use App\Modules\Configuration\Application\DTOs\EditConfigurationVersionData;
use App\Modules\Configuration\Application\DTOs\PublishConfigurationVersionData;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationDefinitionModel;
use App\Modules\Configuration\Presentation\Http\Requests\ConfigurationHistoryRequest;
use App\Modules\Configuration\Presentation\Http\Requests\CreateConfigurationVersionRequest;
use App\Modules\Configuration\Presentation\Http\Requests\DeactivateConfigurationVersionRequest;
use App\Modules\Configuration\Presentation\Http\Requests\EditConfigurationVersionRequest;
use App\Modules\Configuration\Presentation\Http\Requests\PublishConfigurationVersionRequest;
use App\Modules\Configuration\Presentation\Http\Resources\ConfigurationVersionResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class ConfigurationVersionController extends Controller
{
    public function __construct(
        private readonly CreateConfigurationVersionUseCase $createUseCase,
        private readonly EditConfigurationVersionUseCase $editUseCase,
        private readonly PublishConfigurationVersionUseCase $publishUseCase,
        private readonly DeactivateConfigurationVersionUseCase $deactivateUseCase,
    ) {}

    public function index(ConfigurationHistoryRequest $request, string $key): JsonResponse
    {
        $definition = ConfigurationDefinitionModel::query()->where('key', $key)->firstOrFail();

        $query = $definition->versions()->orderBy('version_number', 'desc');

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = (int) $request->input('per_page', 20);

        return ConfigurationVersionResource::collection($query->paginate($perPage))->response();
    }

    public function store(CreateConfigurationVersionRequest $request): JsonResponse
    {
        $version = $this->createUseCase->execute(
            new CreateConfigurationVersionData(
                key: $request->input('key'),
                value: $request->input('value'),
                actorUserId: $request->user()->id,
                idempotencyKey: $request->header('X-Idempotency-Key', (string) Str::uuid()),
            )
        );

        return (new ConfigurationVersionResource($version))
            ->response()
            ->setStatusCode(201);
    }

    public function update(EditConfigurationVersionRequest $request, string $key, string $publicId): JsonResponse
    {
        $version = $this->editUseCase->execute(
            new EditConfigurationVersionData(
                versionPublicId: $publicId,
                value: $request->input('value'),
                lockVersion: (int) $request->input('lock_version'),
                actorUserId: $request->user()->id,
            )
        );

        return (new ConfigurationVersionResource($version))->response();
    }

    public function publish(PublishConfigurationVersionRequest $request, string $key, string $publicId): JsonResponse
    {
        $version = $this->publishUseCase->execute(
            new PublishConfigurationVersionData(
                versionPublicId: $publicId,
                effectiveFrom: CarbonImmutable::parse($request->input('effective_from')),
                reason: $request->input('reason'),
                actorUserId: $request->user()->id,
                idempotencyKey: $request->header('X-Idempotency-Key', (string) Str::uuid()),
            )
        );

        return (new ConfigurationVersionResource($version))->response();
    }

    public function deactivate(DeactivateConfigurationVersionRequest $request, string $key, string $publicId): JsonResponse
    {
        $version = $this->deactivateUseCase->execute(
            new DeactivateConfigurationVersionData(
                versionPublicId: $publicId,
                reason: $request->input('reason'),
                actorUserId: $request->user()->id,
                idempotencyKey: $request->header('X-Idempotency-Key', (string) Str::uuid()),
            )
        );

        return (new ConfigurationVersionResource($version))->response();
    }
}
