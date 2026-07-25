<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Presentation\Http\Controllers;

use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationDefinitionModel;
use App\Modules\Configuration\Infrastructure\Persistence\Repositories\EloquentConfigurationRepository;
use App\Modules\Configuration\Presentation\Http\Requests\ConfigurationListRequest;
use App\Modules\Configuration\Presentation\Http\Resources\ConfigurationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class ConfigurationController extends Controller
{
    public function __construct(
        private readonly EloquentConfigurationRepository $repository,
    ) {}

    public function index(ConfigurationListRequest $request): JsonResponse
    {
        $query = ConfigurationDefinitionModel::query()
            ->with(['currentVersion'])
            ->whereHas('currentVersion');

        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        $perPage = (int) $request->input('per_page', 20);
        $paginator = $query->paginate($perPage);

        // Mapear usando la versión vigente (C12)
        $items = $paginator->getCollection()->map(fn ($def) => $def->currentVersion);
        
        $paginator->setCollection($items);

        return ConfigurationResource::collection($paginator)->response();
    }
}
