<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BaseStatus;
use App\Enums\VersionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Categoria\ActualizarVersionCategoriaRequest;
use App\Http\Requests\Categoria\CrearCategoriaRequest;
use App\Http\Requests\Categoria\CrearVersionCategoriaRequest;
use App\Http\Requests\Categoria\TransicionVersionCategoriaRequest;
use App\Models\Category;
use App\Models\CategoryVersion;
use App\Models\User;
use App\Services\CategoriaServicio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CategoriaController extends Controller
{
    public function __construct(private CategoriaServicio $servicio) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Category::class);
        $query = Category::query()->with($this->visibleVersions($request->user()));
        $this->applyPublishedVisibility($query, $request->user());
        $categorias = $query->get();

        return response()->json($categorias);
    }

    public function store(CrearCategoriaRequest $request)
    {
        Gate::authorize('create', Category::class);
        $categoria = $this->servicio->crearCategoria($request->validated(), $request->user()->id);

        return response()->json($categoria, 201);
    }

    public function show(Request $request, string $id)
    {
        $query = Category::query()->with($this->visibleVersions($request->user()));
        $this->applyPublishedVisibility($query, $request->user());
        $categoria = $query->findOrFail($id);
        Gate::authorize('view', $categoria);

        return response()->json($categoria);
    }

    public function getVersions(Request $request, string $id)
    {
        $query = Category::query();
        $this->applyPublishedVisibility($query, $request->user());
        $categoria = $query->findOrFail($id);
        Gate::authorize('view', $categoria);

        $versions = $categoria->versions()->orderByDesc('version');
        if (! $this->canViewHistory($request->user())) {
            $this->scopePublishedVersion($versions);
        }

        return response()->json($versions->get());
    }

    public function storeVersion(CrearVersionCategoriaRequest $request, string $id)
    {
        $categoria = Category::findOrFail($id);
        Gate::authorize('update', $categoria);
        $version = $this->servicio->crearVersion($categoria, $request->validated(), $request->user()->id);

        return response()->json($version, 201);
    }

    public function showVersion(string $id)
    {
        Gate::authorize('viewAny', CategoryVersion::class);
        $version = CategoryVersion::with('category')->findOrFail($id);
        Gate::authorize('view', $version);

        return response()->json($version);
    }

    public function updateVersion(ActualizarVersionCategoriaRequest $request, string $id)
    {
        $version = CategoryVersion::findOrFail($id);
        Gate::authorize('update', $version);
        try {
            $actualizada = $this->servicio->actualizarVersion($version, $request->validated());

            return response()->json($actualizada);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function publishVersion(TransicionVersionCategoriaRequest $request, string $id)
    {
        $version = CategoryVersion::findOrFail($id);
        Gate::authorize('publish', $version);
        try {
            $publicada = $this->servicio->publicarVersion($version, $request->validated(), $request->user()->id);

            return response()->json($publicada);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function deactivateCategory(Request $request, string $id)
    {
        $categoria = Category::findOrFail($id);
        Gate::authorize('delete', $categoria);
        $desactivada = $this->servicio->desactivarCategoria($categoria, $request->user()->id);

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
