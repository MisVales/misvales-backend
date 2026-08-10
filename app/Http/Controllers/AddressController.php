<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Estado;
use App\Models\Municipio;
use App\Models\CodigoPostal;
use App\Services\GeoapifyService;

class AddressController extends Controller
{
    protected GeoapifyService $geoapifyService;

    public function __construct(GeoapifyService $geoapifyService)
    {
        $this->geoapifyService = $geoapifyService;
    }

    public function getStates()
    {
        return response()->json(Estado::orderBy('name')->get());
    }

    public function getMunicipalities(Estado $estado)
    {
        return response()->json($estado->municipios()->orderBy('name')->get());
    }

    public function getInfoByZipCode($code)
    {
        // El CP puede tener varias colonias y pertenece a un municipio
        $cp = CodigoPostal::with(['municipio.estado', 'colonias' => function($q) {
            $q->orderBy('name');
        }])->where('code', $code)->first();

        if (!$cp) {
            return response()->json(['message' => 'Código Postal no encontrado'], 404);
        }

        return response()->json([
            'estado' => $cp->municipio->estado,
            'municipio' => $cp->municipio,
            'colonias' => $cp->colonias,
            'codigo_postal' => $cp
        ]);
    }

    public function autocomplete(Request $request)
    {
        $request->validate([
            'text' => 'required|string',
            'city' => 'required|string',
            'state' => 'required|string',
            'postcode' => 'required|string',
        ]);

        $result = $this->geoapifyService->autocomplete(
            $request->text,
            $request->city,
            $request->state,
            $request->postcode
        );

        return response()->json($result);
    }

    public function geocode(Request $request)
    {
        $request->validate([
            'street' => 'required|string',
            'number' => 'required|string',
            'neighborhood' => 'required|string',
            'postcode' => 'required|string',
            'city' => 'required|string',
            'state' => 'required|string',
        ]);

        $result = $this->geoapifyService->geocode(
            $request->street,
            $request->number,
            $request->neighborhood,
            $request->postcode,
            $request->city,
            $request->state
        );

        return response()->json($result);
    }
}
