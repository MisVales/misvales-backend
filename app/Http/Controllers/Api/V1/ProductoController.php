<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Services\ProductoServicio;
use Illuminate\Http\Request;

class ProductoController extends Controller
{
    public function __construct(private ProductoServicio $servicio) {}

    public function index()
    {
        $productos = Product::with('versions')->get();
        return response()->json($productos);
    }

    public function store(\App\Http\Requests\Producto\CrearProductoRequest $request)
    {
        $producto = $this->servicio->crearProducto($request->validated(), $request->user()->id);
        return response()->json($producto, 201);
    }

    public function show(string $id)
    {
        $producto = Product::with('versions')->findOrFail($id);
        return response()->json($producto);
    }

    public function getVersions(string $id)
    {
        $producto = Product::findOrFail($id);
        return response()->json($producto->versions()->orderByDesc('version')->get());
    }

    public function storeVersion(\App\Http\Requests\Producto\CrearVersionProductoRequest $request, string $id)
    {
        $producto = Product::findOrFail($id);
        $version = $this->servicio->crearVersion($producto, $request->validated(), $request->user()->id);
        return response()->json($version, 201);
    }

    public function showVersion(string $id)
    {
        $version = ProductVersion::with('product')->findOrFail($id);
        return response()->json($version);
    }

    public function updateVersion(\App\Http\Requests\Producto\ActualizarVersionProductoRequest $request, string $id)
    {
        $version = ProductVersion::findOrFail($id);
        try {
            $actualizada = $this->servicio->actualizarVersion($version, $request->validated());
            return response()->json($actualizada);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function publishVersion(\App\Http\Requests\Producto\TransicionVersionProductoRequest $request, string $id)
    {
        $version = ProductVersion::findOrFail($id);
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
        $desactivada = $this->servicio->desactivarProducto($producto, $request->user()->id);
        return response()->json($desactivada);
    }
}
