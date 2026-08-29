<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BaseStatus;
use App\Enums\VersionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Producto\ActualizarVersionProductoRequest;
use App\Http\Requests\Producto\CrearProductoRequest;
use App\Http\Requests\Producto\CrearVersionProductoRequest;
use App\Http\Requests\Producto\TransicionVersionProductoRequest;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;
use App\Services\ProductoServicio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProductoController extends Controller
{
    public function __construct(private ProductoServicio $servicio) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Product::class);
        $query = Product::query()->with($this->visibleVersions($request->user()));
        $this->applyPublishedVisibility($query, $request->user());
        $busqueda = trim((string) $request->input('search', ''));
        if ($busqueda !== '') {
            $busqueda = mb_substr($busqueda, 0, 120);
            $query->where(function ($productos) use ($busqueda): void {
                $productos
                    ->where('code', 'like', "%{$busqueda}%")
                    ->orWhereHas('versions', fn ($versiones) => $versiones
                        ->where('name', 'like', "%{$busqueda}%")
                        ->orWhere('description', 'like', "%{$busqueda}%"));
            });
        }

        $porPagina = min(max((int) $request->input('per_page', 10), 1), 100);
        $productos = $query->orderBy('code')->paginate($porPagina)->withQueryString();

        return response()->json($productos);
    }

    public function store(CrearProductoRequest $request)
    {
        Gate::authorize('create', Product::class);
        $producto = $this->servicio->crearProducto($request->validated(), $request->user()->id);

        return response()->json($producto, 201);
    }

    public function show(Request $request, string $id)
    {
        $query = Product::query()->with($this->visibleVersions($request->user()));
        $this->applyPublishedVisibility($query, $request->user());
        $producto = $query->findOrFail($id);
        Gate::authorize('view', $producto);

        return response()->json($producto);
    }

    public function getVersions(Request $request, string $id)
    {
        $query = Product::query();
        $this->applyPublishedVisibility($query, $request->user());
        $producto = $query->findOrFail($id);
        Gate::authorize('view', $producto);

        $versions = $producto->versions()->orderByDesc('version');
        if (! $this->canViewHistory($request->user())) {
            $this->scopePublishedVersion($versions);
        }

        return response()->json($versions->get());
    }

    public function storeVersion(CrearVersionProductoRequest $request, string $id)
    {
        $producto = Product::findOrFail($id);
        Gate::authorize('update', $producto);
        $version = $this->servicio->crearVersion($producto, $request->validated(), $request->user()->id);

        return response()->json($version, 201);
    }

    public function showVersion(string $id)
    {
        Gate::authorize('viewAny', ProductVersion::class);
        $version = ProductVersion::with('product')->findOrFail($id);
        Gate::authorize('view', $version);

        return response()->json($version);
    }

    public function updateVersion(ActualizarVersionProductoRequest $request, string $id)
    {
        $version = ProductVersion::findOrFail($id);
        Gate::authorize('update', $version);
        try {
            $actualizada = $this->servicio->actualizarVersion($version, $request->validated());

            return response()->json($actualizada);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function publishVersion(TransicionVersionProductoRequest $request, string $id)
    {
        $version = ProductVersion::findOrFail($id);
        Gate::authorize('publish', $version);
        try {
            $publicada = $this->servicio->publicarVersion($version, $request->validated(), $request->user()->id);

            return response()->json($publicada);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function deactivateProduct(Request $request, string $id)
    {
        $producto = Product::findOrFail($id);
        Gate::authorize('delete', $producto);
        $desactivada = $this->servicio->desactivarProducto($producto, $request->user()->id);

        return response()->json($desactivada);
    }

    private function canViewHistory(User $user): bool
    {
        return $user->hasPermissionTo('catalogs.view_history');
    }

    /** @return array<string, mixed> */
    private function visibleVersions(User $user): array
    {
        if ($this->canViewHistory($user)) {
            return ['versions'];
        }

        return ['versions' => fn ($query) => $this->scopePublishedVersion($query)];
    }

    private function applyPublishedVisibility($query, User $user): void
    {
        if ($this->canViewHistory($user)) {
            return;
        }

        $query
            ->where('status', BaseStatus::ACTIVE)
            ->whereHas('versions', fn ($versions) => $this->publishedVersionConditions($versions));
    }

    private function scopePublishedVersion($query): void
    {
        $this->publishedVersionConditions($query);
        $query->orderByDesc('effective_from');
    }

    private function publishedVersionConditions($query): void
    {
        $query
            ->where('status', VersionStatus::PUBLISHED)
            ->where('effective_from', '<=', now())
            ->where(function ($nested): void {
                $nested->whereNull('effective_to')->orWhere('effective_to', '>', now());
            });
    }
}
