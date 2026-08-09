<?php

namespace App\Services\VerificacionDistribuidora;

use App\Enums\VerificationVisitStatus;
use App\Exceptions\BusinessException;
use App\Helpers\AuditHelper;
use App\Models\DistributorApplication;
use App\Models\MediaFile;
use App\Models\VerificationVisit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ServicioEvidenciaVerificacion
{
    public function __construct(private readonly ServicioAccesoVerificacion $acceso) {}

    public function adjuntarEvidencia(
        string $visitId,
        string $verifierId,
        UploadedFile $file,
        string $fileType,
        int $lockVersion,
    ): MediaFile {
        return DB::transaction(function () use ($visitId, $verifierId, $file, $fileType, $lockVersion): MediaFile {
            $visit = VerificationVisit::query()->lockForUpdate()->find($visitId);
            if ($visit === null) {
                throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
            }

            if ($visit->lock_version !== $lockVersion) {
                throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
            }

            $this->acceso->exigirVerificador($visit, $verifierId);
            if ($visit->status !== VerificationVisitStatus::IN_PROGRESS) {
                throw new BusinessException('VERIFICATION_VISIT_NOT_IN_PROGRESS', 'La visita no está en progreso.', 409);
            }

            $hash = hash_file('sha256', $file->getRealPath());
            if (MediaFile::withTrashed()->where('verification_visit_id', $visit->id)->where('sha256', $hash)->exists()) {
                throw new BusinessException('VERIFICATION_EVIDENCE_DUPLICATE', 'La evidencia ya fue registrada.', 409);
            }

            $path = $file->store("evidences/{$visit->id}", 'local');
            if ($path === false) {
                throw new BusinessException('VERIFICATION_EVIDENCE_STORAGE_FAILED', 'No fue posible conservar la evidencia.', 503);
            }

            $media = MediaFile::query()->create([
                'verification_visit_id' => $visit->id,
                'file_type' => $fileType,
                'disk' => 'local',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => (string) $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'sha256' => $hash,
                'uploaded_by' => $verifierId,
            ]);
            $visit->touch();

            $application = DistributorApplication::query()->findOrFail($visit->application_id);
            AuditHelper::log(
                'VERIFICATION_EVIDENCE_UPLOADED',
                'MediaFile',
                $media->id,
                $verifierId,
                $application->branch_id,
                new: [
                    'file_type' => $fileType,
                    'mime_type' => $media->mime_type,
                    'size_bytes' => $media->size_bytes,
                    'sha256' => $hash,
                ],
                version: $visit->lock_version,
            );

            return $media;
        });
    }

    public function descargarEvidencia(string $visitId, string $mediaId, string $userId)
    {
        $media = MediaFile::query()
            ->where('id', $mediaId)
            ->where('verification_visit_id', $visitId)
            ->first();
        $visit = VerificationVisit::query()->find($visitId);

        if ($media === null || $visit === null) {
            throw new BusinessException('VERIFICATION_EVIDENCE_NOT_FOUND', 'Evidencia no encontrada.', 404);
        }

        $application = DistributorApplication::query()->findOrFail($visit->application_id);
        if (! $this->acceso->puedeConsultar($application, $userId)) {
            throw new BusinessException('VERIFICATION_EVIDENCE_NOT_FOUND', 'Evidencia no encontrada.', 404);
        }

        if (! Storage::disk($media->disk)->exists($media->path)) {
            throw new BusinessException('VERIFICATION_EVIDENCE_NOT_FOUND', 'El archivo de evidencia no está disponible.', 404);
        }

        AuditHelper::log(
            'VERIFICATION_EVIDENCE_DOWNLOADED',
            'MediaFile',
            $media->id,
            $userId,
            $application->branch_id,
        );

        return Storage::disk($media->disk)->download($media->path, $media->original_name);
    }

    public function eliminarEvidenciaAbierta(
        string $visitId,
        string $mediaId,
        string $verifierId,
        int $lockVersion,
    ): void {
        DB::transaction(function () use ($visitId, $mediaId, $verifierId, $lockVersion): void {
            $visit = VerificationVisit::query()->lockForUpdate()->find($visitId);
            $media = MediaFile::query()
                ->where('id', $mediaId)
                ->where('verification_visit_id', $visitId)
                ->first();

            if ($visit === null || $media === null) {
                throw new BusinessException('VERIFICATION_EVIDENCE_NOT_FOUND', 'Evidencia no encontrada.', 404);
            }

            if ($visit->lock_version !== $lockVersion) {
                throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
            }

            $this->acceso->exigirVerificador($visit, $verifierId);
            if ($visit->status !== VerificationVisitStatus::IN_PROGRESS) {
                throw new BusinessException('VERIFICATION_EVIDENCE_IMMUTABLE', 'La evidencia ya es inmutable.', 409);
            }

            $media->delete();
            $visit->touch();
            $application = DistributorApplication::query()->findOrFail($visit->application_id);

            AuditHelper::log(
                'VERIFICATION_EVIDENCE_ARCHIVED',
                'MediaFile',
                $media->id,
                $verifierId,
                $application->branch_id,
                previous: ['sha256' => $media->sha256, 'file_type' => $media->file_type],
                reason: 'Retirada antes de finalizar la visita; el archivo y metadatos se conservan históricamente.',
                version: $visit->lock_version,
            );
        });
    }
}
