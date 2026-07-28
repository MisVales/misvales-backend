<?php

namespace App\Modules\Media\Application\Jobs;

use App\Modules\Media\Persistence\Models\FileValidationAttempt;
use App\Modules\Media\Persistence\Models\MediaFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Job asíncrono que procesa la validación de un archivo recién cargado.
 *
 * Se encarga de aislar la carga del hilo principal de HTTP,
 * evaluar los tipos MIME, extraer el SHA-256 consumiendo el stream
 * y transicionar el estado del archivo a 'AVAILABLE' o 'VALIDATION_FAILED'.
 */
class ValidateUploadedFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $mediaFileId
    ) {}

    public function handle(): void
    {
        DB::transaction(function () {
            // 1. Bloquear el registro de archivo (Sección 16.3.1)
            $file = MediaFile::where('id', $this->mediaFileId)->lockForUpdate()->first();

            if (! $file || ! in_array($file->status, ['PENDING_UPLOAD', 'UPLOADED_TEMPORARY'])) {
                return; // Estado no permite validar
            }

            // Crear intento de validación
            $attempt = FileValidationAttempt::create([
                'id' => Str::uuid()->toString(),
                'file_id' => $file->id,
                'attempt_number' => 1,
                'job_id' => $this->job ? $this->job->getJobId() : 'sync',
                'started_at' => now(),
            ]);

            try {
                $disk = Storage::disk($file->storage_disk);

                if (! $file->temporary_storage_key || ! $disk->exists($file->temporary_storage_key)) {
                    throw new \Exception('FILE_CONTENT_INVALID');
                }

                // 4. Calcular tamaño y SHA-256
                $stream = $disk->readStream($file->temporary_storage_key);
                $hashCtx = hash_init('sha256');
                $size = 0;
                while (! feof($stream)) {
                    $chunk = fread($stream, 8192);
                    $size += strlen($chunk);
                    hash_update($hashCtx, $chunk);
                }
                $sha256 = hash_final($hashCtx);
                fclose($stream);

                // 5. Detectar MIME real
                $mimeType = $disk->mimeType($file->temporary_storage_key);

                // NOTA: Para M18, aquí se invocaría la política definida para comparar
                // $mimeType y $size con los límites permitidos, así como el Antivirus.

                // Simular validación exitosa (mover a clave definitiva)
                $finalKey = 'media/'.date('Y/m/d').'/'.Str::uuid()->toString();
                $disk->move($file->temporary_storage_key, $finalKey);

                $file->update([
                    'status' => 'AVAILABLE',
                    'storage_key' => $finalKey,
                    'temporary_storage_key' => null,
                    'size_bytes' => $size,
                    'sha256' => $sha256,
                    'detected_mime' => $mimeType,
                    'validated_at' => now(),
                    'validated_by_process' => 'ValidateUploadedFileJob',
                    'available_at' => now(),
                ]);

                $attempt->update([
                    'finished_at' => now(),
                    'detected_mime' => $mimeType,
                    'size_bytes' => $size,
                    'sha256' => $sha256,
                    'result' => 'SUCCESS',
                ]);
            } catch (\Exception $e) {
                $file->update([
                    'status' => 'VALIDATION_FAILED',
                    'rejection_code' => $e->getMessage(),
                ]);

                $attempt->update([
                    'finished_at' => now(),
                    'result' => 'FAILED',
                    'error_code' => $e->getMessage(),
                ]);
            }
        });
    }
}
