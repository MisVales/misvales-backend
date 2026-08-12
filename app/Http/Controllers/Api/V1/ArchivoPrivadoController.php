<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use App\Services\Archivos\ServicioArchivosPrivados;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ArchivoPrivadoController extends Controller
{
    public function store(Request $request, ServicioArchivosPrivados $service): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'max:15360'], 'owner_type' => ['required', 'string'], 'owner_id' => ['required', 'uuid'], 'purpose' => ['required', 'string']]);

        return response()->json(['data' => $service->guardar($data['file'], $data['owner_type'], $data['owner_id'], $data['purpose'], $request->user())], 201);
    }

    public function download(MediaFile $media, Request $request, ServicioArchivosPrivados $service): StreamedResponse
    {
        return $service->descargar($media, $request->user());
    }
}
