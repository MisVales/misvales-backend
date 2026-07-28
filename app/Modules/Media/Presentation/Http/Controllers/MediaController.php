<?php

namespace App\Modules\Media\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Media\Application\Jobs\ValidateUploadedFileJob;
use App\Modules\Media\Persistence\Models\FileUploadIntent;
use App\Modules\Media\Persistence\Models\MediaFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Controlador encargado de administrar las peticiones HTTP del módulo Media.
 * Procesa intenciones de carga, consumo y streaming seguro de evidencias.
 */
class MediaController extends Controller
{
    /**
     * Recibe el contenido de un archivo asociado a una intención temporal autorizada.
     * Posiciona el archivo en almacenamiento temporal y encola su validación asíncrona.
     *
     * @tags Media
     * @param Request $request
     * @param FileUploadIntent $intent Intención autorizada generada por el módulo propietario.
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadContent(Request $request, FileUploadIntent $intent)
    {
        $this->authorize('upload', $intent);

        if ($intent->status !== 'PENDING' || $intent->expires_at->isPast()) {
            return response()->json(['error' => 'FILE_UPLOAD_INTENT_EXPIRED'], 400);
        }

        $request->validate([
            'file' => 'required|file', // Validacion basica Laravel
        ]);

        $uploadedFile = $request->file('file');
        
        // 16.2. Guardado en ubicación privada temporal
        $tempKey = 'temp_media/' . Str::uuid()->toString() . '.' . $uploadedFile->getClientOriginalExtension();
        Storage::disk('local')->put($tempKey, file_get_contents($uploadedFile->getRealPath()));

        $mediaFile = MediaFile::create([
            'id' => Str::uuid()->toString(),
            'status' => 'UPLOADED_TEMPORARY',
            'storage_disk' => 'local',
            'storage_key' => 'pending',
            'temporary_storage_key' => $tempKey,
            'original_name' => $uploadedFile->getClientOriginalName(),
            'declared_extension' => $uploadedFile->getClientOriginalExtension(),
            'declared_mime' => $uploadedFile->getClientMimeType(),
            'uploaded_by' => $request->user()->id,
        ]);

        $intent->update([
            'status' => 'CONSUMED',
            'result_file_id' => $mediaFile->id,
            'consumed_at' => now(),
        ]);

        // Encolar validación asíncrona
        ValidateUploadedFileJob::dispatch($mediaFile->id);

        return response()->json([
            'message' => 'Upload received and validation queued',
            'file_id' => $mediaFile->id
        ], 202);
    }
}
