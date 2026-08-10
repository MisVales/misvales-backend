<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Estado;
use App\Models\Municipio;
use App\Models\CodigoPostal;
use App\Services\GeoapifyService;
use Illuminate\Support\Facades\Cache;

class AddressController extends Controller
{
    protected GeoapifyService $geoapifyService;

    public function __construct(GeoapifyService $geoapifyService)
    {
        $this->geoapifyService = $geoapifyService;
    }

    public function getStates()
    {
        $states = Cache::rememberForever('sepomex_states_array', function () {
            return Estado::orderBy('name')->get()->toArray();
        });
        return response()->json($states);
    }

    public function getMunicipalities(Estado $estado)
    {
        $municipalities = Cache::rememberForever('sepomex_municipalities_array_' . $estado->id, function () use ($estado) {
            return $estado->municipios()->orderBy('name')->get()->toArray();
        });
        return response()->json($municipalities);
    }

    public function getInfoByZipCode($code)
    {
        $data = Cache::rememberForever('sepomex_zipcode_array_' . $code, function () use ($code) {
            $cp = CodigoPostal::with(['municipio.estado', 'colonias' => function($q) {
                $q->orderBy('name');
            }])->where('code', $code)->first();

            if (!$cp) return null;

            return [
                'estado' => $cp->municipio->estado->toArray(),
                'municipio' => $cp->municipio->toArray(),
                'colonias' => $cp->colonias->toArray(),
                'codigo_postal' => $cp->toArray()
            ];
        });

        if (!$data) {
            return response()->json(['message' => 'Código Postal no encontrado'], 404);
        }

        return response()->json($data);
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

        $cacheKey = 'geocode_' . md5(implode('|', [
            $request->street, $request->number, $request->neighborhood,
            $request->postcode, $request->city, $request->state
        ]));

        $result = Cache::remember($cacheKey, now()->addDays(30), function () use ($request) {
            return $this->geoapifyService->geocode(
                $request->street,
                $request->number,
                $request->neighborhood,
                $request->postcode,
                $request->city,
                $request->state
            );
        });

        return response()->json($result);
    }
}
