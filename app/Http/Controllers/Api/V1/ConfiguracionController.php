<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConfigurationDefinition;
use App\Models\ConfigurationVersion;
use App\Services\ConfiguracionServicio;
use App\Http\Requests\Configuracion\CrearConfiguracionRequest;
use App\Http\Requests\Configuracion\CrearVersionRequest;
use App\Http\Resources\Configuracion\ConfiguracionResource;
use App\Http\Resources\Configuracion\ConfiguracionVersionResource;
use Illuminate\Http\Request;

class ConfiguracionController extends Controller
{
    public function __construct(private ConfiguracionServicio $servicio) {}

    public function index()
    {
        $configuraciones = ConfigurationDefinition::with(['versions' => function ($q) {
            $q->where('status', \App\Enums\VersionStatus::PUBLISHED)
              ->whereNull('effective_to');
        }])->get();

        return ConfiguracionResource::collection($configuraciones);
    }

    public function store(CrearConfiguracionRequest $request)
    {
        $configuracion = $this->servicio->crearConfiguracion(
            $request->validated(), 
            $request->user()->id
        );

        return (new ConfiguracionResource($configuracion))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $key)
    {
        $configuracion = ConfigurationDefinition::where('key', $key)->firstOrFail();
        return new ConfiguracionResource($configuracion);
    }

    public function getVersionsByKey(string $key)
    {
        $configuracion = ConfigurationDefinition::where('key', $key)->firstOrFail();
        return ConfiguracionVersionResource::collection($configuracion->versions()->orderByDesc('version')->get());
    }

    public function storeVersionByKey(CrearVersionRequest $request, string $key)
    {
        $configuracion = ConfigurationDefinition::where('key', $key)->firstOrFail();
        $version = $this->servicio->crearVersion(
            $configuracion,
            $request->validated(),
            $request->user()->id
        );

        return new ConfiguracionVersionResource($version);
    }

    public function showVersion(string $id)
    {
        $version = ConfigurationVersion::findOrFail($id);
        return new ConfiguracionVersionResource($version);
    }

    public function updateVersion(\App\Http\Requests\Configuracion\ActualizarVersionRequest $request, string $id)
    {
        $version = ConfigurationVersion::findOrFail($id);
        try {
            $actualizada = $this->servicio->actualizarVersion($version, $request->validated());
            return new ConfiguracionVersionResource($actualizada);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function publishVersion(\App\Http\Requests\Configuracion\TransicionVersionRequest $request, string $id)
    {
        $version = ConfigurationVersion::findOrFail($id);

        try {
            $publicada = $this->servicio->publicarVersion($version, $request->user()->id);
            return new ConfiguracionVersionResource($publicada);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function deactivateVersion(\App\Http\Requests\Configuracion\TransicionVersionRequest $request, string $id)
    {
        $version = ConfigurationVersion::findOrFail($id);
        $desactivada = $this->servicio->desactivarVersion($version, $request->user()->id);
        return new ConfiguracionVersionResource($desactivada);
    }

    public function publishNested(Request $request, string $configuration, string $id)
    {
        $version = ConfigurationVersion::query()
            ->where('configuration_definition_id', $configuration)
            ->findOrFail($id);

        return new ConfiguracionVersionResource(
            $this->servicio->publicarVersion($version, $request->user()->id),
        );
    }
}
