<?php

namespace App\Services\VerificacionDistribuidora;

use App\Enums\VerificationVisitStatus;
use App\Exceptions\BusinessException;
use App\Helpers\AuditHelper;
use App\Models\DistributorApplication;
use App\Models\MediaFile;
use App\Models\User;
use App\Models\VerificationVisit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ServicioEvidenciaVerificacion
{
    public function adjuntarEvidencia(string $visitId, string $verifierId, UploadedFile $file, string $fileType, int $lockVersion): MediaFile
    {
        $this->asegurarVerificador($verifierId);

        return DB::transaction(function () use ($visitId, $verifierId, $file, $fileType, $lockVersion) {
            $visit = VerificationVisit::query()
                ->whereKey($visitId)
                ->where('verifier_id', $verifierId)
                ->lockForUpdate()
                ->first();
            if (! $visit) {
                throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
            }
            if ($visit->lock_version !== $lockVersion) {
                throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
            }
            if ($visit->verifier_id !== $verifierId) {
                throw new BusinessException('VERIFICATION_VISIT_NOT_ASSIGNED_TO_USER', 'No autorizado.', 403);
            }
            if ($visit->status === VerificationVisitStatus::COMPLETED) {
                throw new BusinessException('VERIFICATION_VISIT_ALREADY_COMPLETED', 'Visita completada.', 409);
            }
            if ($visit->status !== VerificationVisitStatus::IN_PROGRESS) {
                throw new BusinessException('VERIFICATION_VISIT_NOT_STARTED', 'La visita debe estar EN PROGRESO.', 409);
            }

            $sha256 = hash_file('sha256', $file->getRealPath());
            $exists = MediaFile::where('sha256', $sha256)
                ->whereHas('bindings', fn ($query) => $query
                    ->where('owner_type', 'verification_visit')
                    ->where('owner_id', $visit->id))
                ->exists();
            if ($exists) {
                throw new BusinessException('VERIFICATION_EVIDENCE_DUPLICATE', 'Esta evidencia ya se cargó.', 409);
            }

            $mime = $file->getMimeType();
            $disk = config('filesystems.default');
            $path = $file->store('evidences/'.$visit->id, $disk);

            $media = MediaFile::create([
                'file_type' => $fileType,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $mime,
                'size_bytes' => $file->getSize(),
                'sha256' => $sha256,
                'uploaded_by' => $verifierId,
                'validation_status' => 'VALIDATED',
                'validated_at' => now(),
            ]);
            $media->bindings()->create([
                'owner_type' => 'verification_visit',
                'owner_id' => $visit->id,
                'purpose' => 'EVIDENCE',
                'created_by' => $verifierId,
            ]);

            $visit->touch();
            Log::info("Evidencia subida: {$media->id} para visita {$visitId} por usuario {$verifierId}");
            $newValues = [
                'file_name' => $media->original_name,
                'file_type' => $fileType,
                'mime_type' => $mime,
                'size_bytes' => $file->getSize(),
            ];
            $reason = "Carga de evidencia: {$media->original_name} ({$fileType})";
            AuditHelper::log('VERIFICATION_EVIDENCE_UPLOADED', 'MediaFile', $media->id, $verifierId, null, null, $newValues, $reason, 'SUCCESS');

            return $media;
        });
    }

    public function consultarEvidencia(string $visitId, string $userId): Collection
    {
        $visit = VerificationVisit::find($visitId);
        if (! $visit) {
            throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
        }
        $this->authorizeView($visit, $userId, 'consulta de evidencias');

        return MediaFile::whereHas('bindings', fn ($query) => $query
            ->where('owner_type', 'verification_visit')
            ->where('owner_id', $visitId))
            ->get();
    }

    public function descargarEvidencia(string $mediaId, string $userId, bool $registrarAuditoria = true)
    {
        $media = MediaFile::find($mediaId);
        if (! $media) {
            throw new BusinessException('VERIFICATION_EVIDENCE_NOT_FOUND', 'Evidencia no encontrada.', 404);
        }

        $binding = $media->bindings()->where('owner_type', 'verification_visit')->first();
        $visit = $binding === null ? null : VerificationVisit::find($binding->owner_id);
        if ($visit === null) {
            throw new BusinessException('VERIFICATION_EVIDENCE_NOT_FOUND', 'La evidencia no está vinculada a una visita.', 404);
        }
        $application = $this->authorizeView($visit, $userId, 'descarga de evidencia', $mediaId);

        if (! Storage::disk($media->disk)->exists($media->path)) {
            throw new BusinessException('VERIFICATION_EVIDENCE_NOT_FOUND', 'El archivo no existe.', 404);
        }

        if ($registrarAuditoria) {
            Log::info("Descarga de evidencia {$mediaId} realizada por usuario {$userId}");
            $downloadValues = [
                'file_name' => $media->original_name,
                'file_type' => $media->file_type,
                'mime_type' => $media->mime_type,
                'size_bytes' => $media->size_bytes,
            ];
            AuditHelper::log('VERIFICATION_EVIDENCE_DOWNLOADED', 'MediaFile', $media->id, $userId, $application->branch_id, null, $downloadValues, "Descarga de evidencia: {$media->original_name}", 'SUCCESS');
        }

        return Storage::disk($media->disk)->download($media->path, $media->original_name);
    }

    public function eliminarEvidenciaAbierta(string $mediaId, string $verifierId): void
    {
        $this->asegurarVerificador($verifierId);

        DB::transaction(function () use ($mediaId, $verifierId) {
            $media = MediaFile::find($mediaId);
            if (! $media) {
                throw new BusinessException('VERIFICATION_EVIDENCE_NOT_FOUND', 'Evidencia no encontrada.', 404);
            }
            $binding = $media->bindings()->where('owner_type', 'verification_visit')->first();
            $visit = $binding === null ? null : VerificationVisit::query()
                ->whereKey($binding->owner_id)
                ->where('verifier_id', $verifierId)
                ->lockForUpdate()
                ->first();
            if ($visit === null) {
                throw new BusinessException('VERIFICATION_EVIDENCE_NOT_FOUND', 'La evidencia no está vinculada a una visita.', 404);
            }

            if ($visit->verifier_id !== $verifierId) {
                AuditHelper::log('VERIFICATION_ACCESS_DENIED', 'MediaFile', $mediaId, $verifierId, null, null, null, 'Intento de eliminación', 'DENIED');
                throw new BusinessException('VERIFICATION_VISIT_NOT_ASSIGNED_TO_USER', 'No autorizado.', 403);
            }
            if ($visit->status === VerificationVisitStatus::COMPLETED) {
                throw new BusinessException('VERIFICATION_VISIT_ALREADY_COMPLETED', 'No se pueden eliminar evidencias de una visita finalizada.', 409);
            }

            Storage::disk($media->disk)->delete($media->path);
            $media->delete();
            $visit->touch();

            $removeValues = [
                'file_name' => $media->original_name,
                'file_type' => $media->file_type,
                'size_bytes' => $media->size_bytes,
            ];
            AuditHelper::log('VERIFICATION_EVIDENCE_REMOVED', 'MediaFile', $mediaId, $verifierId, null, $removeValues, null, "Evidencia eliminada: {$media->original_name}", 'SUCCESS');
        });
    }

    private function asegurarVerificador(string $userId): void
    {
        $user = User::query()->find($userId);
        if ($user === null || ! $user->hasRole('verifier')) {
            throw new BusinessException('AUTH_SCOPE_DENIED', 'No tienes permiso para gestionar evidencias.', 403);
        }
    }

    private function authorizeView(VerificationVisit $visit, string $userId, string $action, ?string $entityId = null): DistributorApplication
    {
        $application = DistributorApplication::find($visit->application_id);
        $user = User::find($userId);

        if (! $application) {
            throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
        }

        $canViewEvidence = $user !== null && (
            $user->hasRole('general_manager')
            || $user->hasRole('admin')
            || $user->hasRole('verifier')
            || $user->hasRole('coordinator')
            || $user->hasRole('branch_manager')
        );
        if (! $canViewEvidence) {
            throw new BusinessException('AUTH_SCOPE_DENIED', 'No tienes permiso para consultar evidencias.', 403);
        }

        $authorized = ($user->hasRole('general_manager') || $user->hasRole('admin'))
            || ($user->hasRole('verifier') && $visit->verifier_id === $userId)
            || ($user->hasRole('coordinator') && $application->coordinator_id === $userId)
            || ($user->hasRole('branch_manager') && $user->hasScopeForBranch($application->branch_id));

        if (! $authorized) {
            AuditHelper::log(
                'VERIFICATION_ACCESS_DENIED',
                $entityId === null ? 'VerificationVisit' : 'MediaFile',
                $entityId ?? $visit->id,
                $userId,
                $application->branch_id,
                null,
                null,
                'Intento no autorizado de '.$action,
                'DENIED'
            );
            throw new BusinessException('VERIFICATION_EVIDENCE_NOT_FOUND', 'Evidencia no encontrada.', 404);
        }

        return $application;
    }
}
