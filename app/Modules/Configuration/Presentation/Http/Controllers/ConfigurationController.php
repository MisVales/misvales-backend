<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Presentation\Http\Controllers;

use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationDefinitionModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationVersionModel;
use App\Modules\Configuration\Presentation\Http\Requests\ConfigurationListRequest;
use App\Modules\Configuration\Presentation\Http\Resources\ConfigurationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;

final class ConfigurationController extends Controller
{
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
        $items = $paginator->getCollection()
            ->map(fn (ConfigurationDefinitionModel $definition): ?ConfigurationVersionModel => $definition->currentVersion)
            ->filter(fn (?ConfigurationVersionModel $version): bool => $version !== null)
            ->values();
        $versions = new LengthAwarePaginator(
            $items,
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            $paginator->getOptions(),
        );

        return ConfigurationResource::collection($versions)->response();
    }
}
