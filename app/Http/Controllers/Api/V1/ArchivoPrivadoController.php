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
        $data = $request->validate(
            [
                'file' => ['bail', 'required', 'file', 'max:15360'],
                'owner_type' => ['bail', 'required', 'string', 'max:80'],
                'owner_id' => ['bail', 'required', 'uuid'],
                'purpose' => ['bail', 'required', 'string', 'max:80'],
            ],
            [
                'file.required' => 'Selecciona un archivo.',
                'file.file' => 'El archivo enviado no es válido.',
                'file.max' => 'Archivo demasiado grande. El tamaño máximo es 15 MB.',
                'owner_type.required' => 'El tipo de registro propietario es obligatorio.',
                'owner_type.max' => 'El tipo de registro propietario no es válido.',
                'owner_id.required' => 'El registro propietario es obligatorio.',
                'owner_id.uuid' => 'El registro propietario no es válido.',
                'purpose.required' => 'El propósito del archivo es obligatorio.',
                'purpose.max' => 'El propósito del archivo no es válido.',
            ],
        );

        return response()->json(['data' => $service->guardar($data['file'], $data['owner_type'], $data['owner_id'], $data['purpose'], $request->user())], 201);
    }

    public function download(MediaFile $media, Request $request, ServicioArchivosPrivados $service): StreamedResponse
    {
        return $service->descargar($media, $request->user());
    }
}
