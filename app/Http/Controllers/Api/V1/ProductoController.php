<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Producto\ActualizarVersionProductoRequest;
use App\Http\Requests\Producto\CrearProductoRequest;
use App\Http\Requests\Producto\CrearVersionProductoRequest;
use App\Http\Requests\Producto\TransicionVersionProductoRequest;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Services\ProductoServicio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProductoController extends Controller
{
    public function __construct(private ProductoServicio $servicio) {}

    public function index()
    {
        Gate::authorize('viewAny', Product::class);
        $productos = Product::with('versions')->get();

        return response()->json($productos);
    }

    public function store(CrearProductoRequest $request)
    {
        Gate::authorize('create', Product::class);
        $producto = $this->servicio->crearProducto($request->validated(), $request->user()->id);

        return response()->json($producto, 201);
    }

    public function show(string $id)
    {
        $producto = Product::with('versions')->findOrFail($id);
        Gate::authorize('view', $producto);

        return response()->json($producto);
    }

    public function getVersions(string $id)
    {
        $producto = Product::findOrFail($id);
        Gate::authorize('view', $producto);

        return response()->json($producto->versions()->orderByDesc('version')->get());
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
}
