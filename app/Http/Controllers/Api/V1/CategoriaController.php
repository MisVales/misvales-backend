<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryVersion;
use App\Services\CategoriaServicio;
use Illuminate\Http\Request;

class CategoriaController extends Controller
{
    public function __construct(private CategoriaServicio $servicio) {}

    public function index()
    {
        $categorias = Category::with('versions')->get();
        return response()->json($categorias);
    }

    public function store(\App\Http\Requests\Categoria\CrearCategoriaRequest $request)
    {
        $categoria = $this->servicio->crearCategoria($request->validated(), $request->user()->id);
        return response()->json($categoria, 201);
    }

    public function show(string $id)
    {
        $categoria = Category::with('versions')->findOrFail($id);
        return response()->json($categoria);
    }

    public function getVersions(string $id)
    {
        $categoria = Category::findOrFail($id);
        return response()->json($categoria->versions()->orderByDesc('version')->get());
    }

    public function storeVersion(\App\Http\Requests\Categoria\CrearVersionCategoriaRequest $request, string $id)
    {
        $categoria = Category::findOrFail($id);
        $version = $this->servicio->crearVersion($categoria, $request->validated(), $request->user()->id);
        return response()->json($version, 201);
    }

    public function showVersion(string $id)
    {
        $version = CategoryVersion::with('category')->findOrFail($id);
        return response()->json($version);
    }

    public function updateVersion(\App\Http\Requests\Categoria\ActualizarVersionCategoriaRequest $request, string $id)
    {
        $version = CategoryVersion::findOrFail($id);
        try {
            $actualizada = $this->servicio->actualizarVersion($version, $request->validated());
            return response()->json($actualizada);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function publishVersion(\App\Http\Requests\Categoria\TransicionVersionCategoriaRequest $request, string $id)
    {
        $version = CategoryVersion::findOrFail($id);
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
        $desactivada = $this->servicio->desactivarCategoria($categoria, $request->user()->id);
        return response()->json($desactivada);
    }
}
