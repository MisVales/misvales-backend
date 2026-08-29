<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BaseStatus;
use App\Enums\VersionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\ActualizarConfiguracionActualRequest;
use App\Http\Requests\Configuracion\ActualizarVersionRequest;
use App\Http\Requests\Configuracion\CrearConfiguracionRequest;
use App\Http\Requests\Configuracion\CrearVersionRequest;
use App\Http\Requests\Configuracion\TransicionVersionRequest;
use App\Http\Resources\Configuracion\ConfiguracionResource;
use App\Http\Resources\Configuracion\ConfiguracionVersionResource;
use App\Models\ConfigurationDefinition;
use App\Models\ConfigurationVersion;
use App\Services\ConfiguracionServicio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ConfiguracionController extends Controller
{
    private const FINANCIAL_PRODUCT_KEYS = [
        'LOAN_COMMISSION_PERCENTAGE',
        'INTEREST_RATE_PER_FORTNIGHT',
        'VOUCHER_INSURANCE_AMOUNT',
        'VOUCHER_MIN_FORTNIGHTS_COUNT',
        'VOUCHER_MAX_FORTNIGHTS_COUNT',
    ];

    public function __construct(private ConfiguracionServicio $servicio) {}

    public function index()
    {
        Gate::authorize('viewAny', ConfigurationDefinition::class);
        $configuraciones = ConfigurationDefinition::with(['versions' => function ($q) {
            $q->where('status', VersionStatus::PUBLISHED)
                ->where('effective_from', '<=', now())
                ->where(function ($query) {
                    $query->whereNull('effective_to')
                        ->orWhere('effective_to', '>', now());
                })
                ->latest('effective_from');
        }])
            ->where('status', BaseStatus::ACTIVE)
            ->whereNotIn('key', self::FINANCIAL_PRODUCT_KEYS)
            ->get();

        return ConfiguracionResource::collection($configuraciones);
    }

    public function store(CrearConfiguracionRequest $request)
    {
        Gate::authorize('create', ConfigurationDefinition::class);
        $datos = $request->validated();
        $this->rechazarConfiguracionFinancieraRetirada($datos['key']);
        $configuracion = $this->servicio->crearConfiguracion(
            $datos,
            $request->user()->id
        );

        return (new ConfiguracionResource($configuracion))->response()->setStatusCode(201);
    }

    public function show(string $key)
    {
        $configuracion = ConfigurationDefinition::with(['versions' => function ($query) {
            $query->where('status', VersionStatus::PUBLISHED)
                ->where('effective_from', '<=', now())
                ->where(function ($query) {
                    $query->whereNull('effective_to')
                        ->orWhere('effective_to', '>', now());
                })
                ->latest('effective_from');
        }])->where('key', $key)
            ->where('status', BaseStatus::ACTIVE)
            ->whereNotIn('key', self::FINANCIAL_PRODUCT_KEYS)
            ->firstOrFail();
        Gate::authorize('view', $configuracion);

        return new ConfiguracionResource($configuracion);
    }

    public function getVersionsByKey(Request $request, string $key)
    {
        $configuracion = $this->buscarConfiguracionActiva($key);
        Gate::authorize('view', $configuracion);

        $versions = $configuracion->versions()->orderByDesc('version');
        if (! $request->user()->hasPermissionTo('catalogs.view_history')) {
            $versions
                ->where('status', VersionStatus::PUBLISHED)
                ->where('effective_from', '<=', now())
                ->where(function ($query): void {
                    $query->whereNull('effective_to')->orWhere('effective_to', '>', now());
                });
        }

        return ConfiguracionVersionResource::collection($versions->get());
    }

    public function storeVersionByKey(CrearVersionRequest $request, string $key)
    {
        $configuracion = $this->buscarConfiguracionActiva($key);
        Gate::authorize('update', $configuracion);
        $version = $this->servicio->crearVersion(
            $configuracion,
            $request->validated(),
            $request->user()->id
        );

        return (new ConfiguracionVersionResource($version))->response()->setStatusCode(201);
    }

    public function updateCurrent(ActualizarConfiguracionActualRequest $request, string $key)
    {
        $configuracion = $this->buscarConfiguracionActiva($key);
        Gate::authorize('update', $configuracion);

        $actualizada = $this->servicio->actualizarValorActual(
            $configuracion,
            $request->validated(),
            $request->user()->id,
        );

        return (new ConfiguracionVersionResource($actualizada))->response()->setStatusCode(200);
    }

    public function showVersion(string $id)
    {
        $version = $this->findActiveVersion($id);
        Gate::authorize('view', $version);

        return new ConfiguracionVersionResource($version);
    }

    public function updateVersion(ActualizarVersionRequest $request, string $id)
    {
        $version = $this->findActiveVersion($id);
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
        $version = $this->findActiveVersion($id);
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
        $version = $this->findActiveVersion($id);
        Gate::authorize('delete', $version);
        $desactivada = $this->servicio->desactivarVersion($version, $request->validated(), $request->user()->id);

        return new ConfiguracionVersionResource($desactivada);
    }

    private function findActiveVersion(string $id): ConfigurationVersion
    {
        return ConfigurationVersion::query()
            ->with('definition')
            ->whereHas('definition', fn ($query) => $query
                ->where('status', BaseStatus::ACTIVE)
                ->whereNotIn('key', self::FINANCIAL_PRODUCT_KEYS))
            ->findOrFail($id);
    }

    private function buscarConfiguracionActiva(string $key): ConfigurationDefinition
    {
        return ConfigurationDefinition::query()
            ->where('key', $key)
            ->where('status', BaseStatus::ACTIVE)
            ->whereNotIn('key', self::FINANCIAL_PRODUCT_KEYS)
            ->firstOrFail();
    }

    private function rechazarConfiguracionFinancieraRetirada(string $key): void
    {
        if (! in_array($key, self::FINANCIAL_PRODUCT_KEYS, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'key' => 'Esta condición financiera se configura por producto y no puede crearse como configuración global.',
        ]);
    }
}
