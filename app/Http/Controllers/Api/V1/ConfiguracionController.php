<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\VersionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\ActualizarVersionRequest;
use App\Http\Requests\Configuracion\CrearConfiguracionRequest;
use App\Http\Requests\Configuracion\CrearVersionRequest;
use App\Http\Requests\Configuracion\TransicionVersionRequest;
use App\Http\Resources\Configuracion\ConfiguracionResource;
use App\Http\Resources\Configuracion\ConfiguracionVersionResource;
use App\Models\ConfigurationDefinition;
use App\Models\ConfigurationVersion;
use App\Services\ConfiguracionServicio;
use Illuminate\Support\Facades\Gate;

class ConfiguracionController extends Controller
{
    public function __construct(private ConfiguracionServicio $servicio) {}

    public function index()
    {
        Gate::authorize('viewAny', ConfigurationDefinition::class);
        $configuraciones = ConfigurationDefinition::with(['versions' => function ($q) {
            $q->where('status', VersionStatus::PUBLISHED)
                ->whereNull('effective_to');
        }])->get();

        return ConfiguracionResource::collection($configuraciones);
    }

    public function store(CrearConfiguracionRequest $request)
    {
        Gate::authorize('create', ConfigurationDefinition::class);
        $configuracion = $this->servicio->crearConfiguracion(
            $request->validated(),
            $request->user()->id
        );

        return (new ConfiguracionResource($configuracion))->response()->setStatusCode(201);
    }

    public function show(string $key)
    {
        $configuracion = ConfigurationDefinition::where('key', $key)->firstOrFail();
        Gate::authorize('view', $configuracion);

        return new ConfiguracionResource($configuracion);
    }

    public function getVersionsByKey(string $key)
    {
        $configuracion = ConfigurationDefinition::where('key', $key)->firstOrFail();
        Gate::authorize('view', $configuracion);

        return ConfiguracionVersionResource::collection($configuracion->versions()->orderByDesc('version')->get());
    }

    public function storeVersionByKey(CrearVersionRequest $request, string $key)
    {
        $configuracion = ConfigurationDefinition::where('key', $key)->firstOrFail();
        Gate::authorize('update', $configuracion);
        $version = $this->servicio->crearVersion(
            $configuracion,
            $request->validated(),
            $request->user()->id
        );

        return (new ConfiguracionVersionResource($version))->response()->setStatusCode(201);
    }

    public function showVersion(string $id)
    {
        $version = ConfigurationVersion::findOrFail($id);
        Gate::authorize('view', $version);

        return new ConfiguracionVersionResource($version);
    }

    public function updateVersion(ActualizarVersionRequest $request, string $id)
    {
        $version = ConfigurationVersion::findOrFail($id);
        Gate::authorize('update', $version);
        try {
            $actualizada = $this->servicio->actualizarVersion($version, $request->validated());

            return new ConfiguracionVersionResource($actualizada);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function publishVersion(TransicionVersionRequest $request, string $id)
    {
        $version = ConfigurationVersion::findOrFail($id);
        Gate::authorize('publish', $version);

        try {
            $publicada = $this->servicio->publicarVersion($version, $request->validated(), $request->user()->id);

            return new ConfiguracionVersionResource($publicada);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function deactivateVersion(TransicionVersionRequest $request, string $id)
    {
        $version = ConfigurationVersion::findOrFail($id);
        Gate::authorize('delete', $version);
        $desactivada = $this->servicio->desactivarVersion($version, $request->validated(), $request->user()->id);

        return new ConfiguracionVersionResource($desactivada);
    }
}
