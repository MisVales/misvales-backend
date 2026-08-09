<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Categoria\ActualizarVersionCategoriaRequest;
use App\Http\Requests\Categoria\CrearCategoriaRequest;
use App\Http\Requests\Categoria\CrearVersionCategoriaRequest;
use App\Http\Requests\Categoria\TransicionVersionCategoriaRequest;
use App\Models\Category;
use App\Models\CategoryVersion;
use App\Services\CategoriaServicio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CategoriaController extends Controller
{
    public function __construct(private CategoriaServicio $servicio) {}

    public function index()
    {
        Gate::authorize('viewAny', Category::class);
        $categorias = Category::with('versions')->get();

        return response()->json($categorias);
    }

    public function store(CrearCategoriaRequest $request)
    {
        Gate::authorize('create', Category::class);
        $categoria = $this->servicio->crearCategoria($request->validated(), $request->user()->id);

        return response()->json($categoria, 201);
    }

    public function show(string $id)
    {
        $categoria = Category::with('versions')->findOrFail($id);
        Gate::authorize('view', $categoria);

        return response()->json($categoria);
    }

    public function getVersions(string $id)
    {
        $categoria = Category::findOrFail($id);
        Gate::authorize('view', $categoria);

        return response()->json($categoria->versions()->orderByDesc('version')->get());
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
}
