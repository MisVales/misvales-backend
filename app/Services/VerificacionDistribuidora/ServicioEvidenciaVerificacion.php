<?php
namespace App\Services\VerificacionDistribuidora;
use App\Models\VerificationVisit;
use App\Models\MediaFile;
use App\Models\DistributorApplication;
use App\Models\User;
use App\Enums\VerificationVisitStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Exceptions\BusinessException;
use Illuminate\Database\Eloquent\Collection;
use App\Helpers\AuditHelper;

class ServicioEvidenciaVerificacion {
    
    public function adjuntarEvidencia(string $visitId, string $verifierId, UploadedFile $file, string $fileType, int $lockVersion): MediaFile {
        return DB::transaction(function () use ($visitId, $verifierId, $file, $fileType, $lockVersion) {
            $visit = VerificationVisit::lockForUpdate()->find($visitId);
            if (!$visit) throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
            if ($visit->lock_version !== $lockVersion) throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
            if ($visit->verifier_id !== $verifierId) throw new BusinessException('VERIFICATION_VISIT_NOT_ASSIGNED_TO_USER', 'No autorizado.', 403);
            if ($visit->status === VerificationVisitStatus::COMPLETED) throw new BusinessException('VERIFICATION_VISIT_ALREADY_COMPLETED', 'Visita completada.', 409);
            if ($visit->status !== VerificationVisitStatus::IN_PROGRESS) throw new BusinessException('VERIFICATION_VISIT_NOT_STARTED', 'La visita debe estar EN PROGRESO.', 409);

            $sha256 = hash_file('sha256', $file->getRealPath());
            $exists = MediaFile::where('verification_visit_id', $visit->id)->where('sha256', $sha256)->exists();
            if ($exists) {
                throw new BusinessException('VERIFICATION_EVIDENCE_DUPLICATE', 'Evidencia duplicada.', 409);
            }

            $mime = $file->getMimeType();
            $path = $file->store('evidences/' . $visit->id, 'private');

            $media = MediaFile::create([
                'verification_visit_id' => $visit->id,
                'file_type' => $fileType,
                'disk' => 'private',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $mime,
                'size_bytes' => $file->getSize(),
                'sha256' => $sha256,
                'uploaded_by' => $verifierId
            ]);
            
            $visit->touch();
            Log::info("Evidencia subida: {$media->id} para visita {$visitId} por usuario {$verifierId}");
            AuditHelper::log('VERIFICATION_EVIDENCE_UPLOADED', 'MediaFile', $media->id, $verifierId, null, null, ['file_type' => $fileType], null, 'SUCCESS');
            return $media;
        });
    }

    public function consultarEvidencia(string $visitId): Collection {
        return MediaFile::where('verification_visit_id', $visitId)->get();
    }

    public function descargarEvidencia(string $mediaId, string $userId) {
        $media = MediaFile::find($mediaId);
        if (!$media) throw new BusinessException('VERIFICATION_EVIDENCE_NOT_FOUND', 'Evidencia no encontrada.', 404);
        
        $visit = VerificationVisit::find($media->verification_visit_id);
        $application = DistributorApplication::find($visit->application_id);
        $user = User::find($userId);

        $isGeneralManager = method_exists($user, 'hasRole') ? $user->hasRole('general_manager') : false;
        $isAdmin = method_exists($user, 'hasRole') ? $user->hasRole('admin') : false;
        
        $authorized = false;
        if ($isGeneralManager || $isAdmin) {
            $authorized = true;
        } elseif ($visit->verifier_id === $userId) {
            $authorized = true;
        } elseif ($application->coordinator_id === $userId) {
            $authorized = true;
        } elseif (method_exists($user, 'hasRole') && $user->hasRole('branch_manager') && $user->branch_id === $application->branch_id) {
            $authorized = true;
        }

        if (!$authorized) {
            AuditHelper::log('VERIFICATION_ACCESS_DENIED', 'MediaFile', $mediaId, $userId, $application->branch_id, null, null, 'Intento de descarga de evidencia', 'DENIED');
            throw new BusinessException('AUTH_SCOPE_DENIED', 'No tiene permisos para descargar esta evidencia.', 403);
        }

        if (!Storage::disk($media->disk)->exists($media->path)) throw new BusinessException('VERIFICATION_EVIDENCE_NOT_FOUND', 'El archivo no existe.', 404);
        
        Log::info("Descarga de evidencia {$mediaId} realizada por usuario {$userId}");
        return Storage::disk($media->disk)->download($media->path, $media->original_name);
    }

    public function eliminarEvidenciaAbierta(string $mediaId, string $verifierId): void {
        DB::transaction(function () use ($mediaId, $verifierId) {
            $media = MediaFile::find($mediaId);
            if (!$media) throw new BusinessException('VERIFICATION_EVIDENCE_NOT_FOUND', 'Evidencia no encontrada.', 404);
            $visit = VerificationVisit::lockForUpdate()->find($media->verification_visit_id);

            if ($visit->verifier_id !== $verifierId) {
                AuditHelper::log('VERIFICATION_ACCESS_DENIED', 'MediaFile', $mediaId, $verifierId, null, null, null, 'Intento de eliminación', 'DENIED');
                throw new BusinessException('VERIFICATION_VISIT_NOT_ASSIGNED_TO_USER', 'No autorizado.', 403);
            }
            if ($visit->status === VerificationVisitStatus::COMPLETED) throw new BusinessException('VERIFICATION_VISIT_ALREADY_COMPLETED', 'No se pueden eliminar evidencias de una visita finalizada.', 409);

            Storage::disk($media->disk)->delete($media->path);
            $media->delete();
            $visit->touch();
            
            AuditHelper::log('VERIFICATION_EVIDENCE_REMOVED', 'MediaFile', $mediaId, $verifierId, null, null, null, 'Eliminada por el verificador', 'SUCCESS');
        });
    }
}
