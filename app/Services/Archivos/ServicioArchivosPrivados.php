<?php

namespace App\Services\Archivos;

use App\Helpers\AuditHelper;
use App\Models\MediaFile;
use App\Models\MediaFileBinding;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ServicioArchivosPrivados
{
    private const PURPOSES = [
        'PHOTO' => ['jpg', 'jpeg', 'jfif', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff', 'heic', 'heif', 'avif'],
        'IDENTIFICATION' => ['jpg', 'jpeg', 'jfif', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff', 'heic', 'heif', 'avif', 'pdf'],
        'ADDRESS_PROOF' => ['jpg', 'jpeg', 'jfif', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff', 'heic', 'heif', 'avif', 'pdf'],
        'VEHICLE_EVIDENCE' => ['jpg', 'jpeg', 'jfif', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff', 'heic', 'heif', 'avif', 'pdf'],
        'ASSET_EVIDENCE' => ['jpg', 'jpeg', 'jfif', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff', 'heic', 'heif', 'avif', 'pdf'],
        'COMMERCIAL_EVIDENCE' => ['jpg', 'jpeg', 'jfif', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff', 'heic', 'heif', 'avif', 'pdf'],
        'RECEIPT' => ['jpg', 'jpeg', 'jfif', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff', 'heic', 'heif', 'avif', 'pdf'],
        'CLARIFICATION' => ['jpg', 'jpeg', 'jfif', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff', 'heic', 'heif', 'avif', 'pdf'],
        'REFUND_EVIDENCE' => ['jpg', 'jpeg', 'jfif', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff', 'heic', 'heif', 'avif', 'pdf'],
        'BANK_XLSX' => ['xlsx'],
        'GENERATED_DOCUMENT' => ['pdf'],
    ];

    private const OWNERS = ['verification_visit', 'client', 'payment_clarification', 'surplus_refund_request', 'bank_file_import', 'distributor_relation', 'distributor_application', 'application_vehicle', 'application_asset_liability', 'application_commercial_credit'];

    private const RECORD_OWNER_PURPOSES = [
        'application_vehicle' => 'VEHICLE_EVIDENCE',
        'application_asset_liability' => 'ASSET_EVIDENCE',
        'application_commercial_credit' => 'COMMERCIAL_EVIDENCE',
    ];

    public function guardar(UploadedFile $file, string $ownerType, string $ownerId, string $purpose, User $actor): MediaFile
    {
        abort_unless($actor->hasPermissionTo('media.upload'), 403);
        $purpose = strtoupper($purpose);
        if (! in_array($ownerType, self::OWNERS, true) || ! isset(self::PURPOSES[$purpose])) {
            throw ValidationException::withMessages(['context' => ['Contexto o propósito de archivo no permitido.']]);
        }
        if (isset(self::RECORD_OWNER_PURPOSES[$ownerType]) && self::RECORD_OWNER_PURPOSES[$ownerType] !== $purpose) {
            throw ValidationException::withMessages(['context' => ['El propósito no corresponde al tipo de registro.']]);
        }
        $branchId = $this->branchId($ownerType, $ownerId);
        if (! $branchId) {
            throw ValidationException::withMessages(['owner_id' => ['La entidad propietaria no existe.']]);
        }
        $ownsClient = $ownerType === 'client' && DB::table('client_distributor_assignments as a')->join('distributors as d', 'd.id', '=', 'a.distributor_id')->where('a.client_id', $ownerId)->whereNull('a.ends_at')->where('d.user_id', $actor->id)->exists();
        abort_unless($actor->hasPermissionTo('media.download_global') || $actor->hasScopeForBranch($branchId) || $ownsClient, 403);
        $clientExtension = strtolower($file->getClientOriginalExtension());
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $extension = $this->canonicalExtension($mime);
        $extensionAliases = ['jpeg' => 'jpg', 'jfif' => 'jpg', 'tif' => 'tiff'];
        $normalizedClientExtension = $extensionAliases[$clientExtension] ?? $clientExtension;
        $allowedExtensions = implode(', ', array_map(static fn (string $value): string => '.'.$value, self::PURPOSES[$purpose]));
        $maxBytes = $purpose === 'BANK_XLSX' ? 10 * 1024 * 1024 : 15 * 1024 * 1024;

        if (! $file->getSize() || $file->getSize() <= 0) {
            throw ValidationException::withMessages(['file' => ['El archivo está vacío.']]);
        }
        if ($file->getSize() > $maxBytes) {
            throw ValidationException::withMessages(['file' => ['Archivo demasiado grande. El tamaño máximo es '.($maxBytes / 1024 / 1024).' MB.']]);
        }
        if ($extension === null || ! in_array($extension, self::PURPOSES[$purpose], true)) {
            throw ValidationException::withMessages(['file' => ['Archivo inválido. Solo se aceptan: '.$allowedExtensions.'.']]);
        }
        if ($normalizedClientExtension !== $extension) {
            throw ValidationException::withMessages(['file' => ['La extensión del archivo no coincide con su tipo real. Solo se aceptan: '.$allowedExtensions.'.']]);
        }
        if ($extension === 'xlsx') {
            $this->validarLibroXlsx($file);
        }
        $hash = hash_file('sha256', $file->getRealPath());

        return DB::transaction(function () use ($file, $ownerType, $ownerId, $purpose, $actor, $extension, $mime, $hash): MediaFile {
            $disk = config('filesystems.default');
            $temporary = 'tmp/'.Str::uuid();
            Storage::disk($disk)->putFileAs('tmp', $file, basename($temporary));
            $destination = 'media/'.now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
            if (! Storage::disk($disk)->move($temporary, $destination)) {
                throw ValidationException::withMessages(['file' => ['No fue posible finalizar el almacenamiento privado.']]);
            }
            $media = MediaFile::query()->create(['file_type' => $purpose, 'disk' => $disk, 'path' => $destination, 'original_name' => basename($file->getClientOriginalName()), 'mime_type' => $mime, 'size_bytes' => $file->getSize(), 'sha256' => $hash, 'uploaded_by' => $actor->id, 'validation_status' => 'VALIDATED', 'validated_at' => now()]);
            MediaFileBinding::query()->create(['media_file_id' => $media->id, 'owner_type' => $ownerType, 'owner_id' => $ownerId, 'purpose' => $purpose, 'created_by' => $actor->id]);
            $applicationId = $this->applicationId($ownerType, $ownerId);
            if ($applicationId !== null && $ownerType !== 'distributor_application') {
                MediaFileBinding::query()->firstOrCreate(
                    ['media_file_id' => $media->id, 'owner_type' => 'distributor_application', 'owner_id' => $applicationId, 'purpose' => $purpose],
                    ['created_by' => $actor->id],
                );
            }
            $storedValues = [
                'file_name' => $media->original_name,
                'purpose' => $purpose,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'mime_type' => $mime,
                'size_bytes' => $file->getSize(),
                'sha256' => $hash,
            ];
            AuditHelper::log('PRIVATE_MEDIA_STORED', 'media_file', $media->id, $actor->id, $actor->branch_id, null, $storedValues, "Carga de archivo: {$media->original_name} ({$purpose})");

            return $media;
        }, 3);
    }

    public function descargar(MediaFile $media, User $actor): StreamedResponse
    {
        $bindings = $media->bindings()->get();
        abort_if($bindings->isEmpty(), 404);

        $authorized = $actor->hasPermissionTo('media.download_global') || $media->uploaded_by === $actor->id || $bindings->contains('created_by', $actor->id);

        if (! $authorized) {
            foreach ($bindings as $binding) {
                if (in_array($binding->purpose, ['IDENTIFICATION', 'ADDRESS_PROOF', 'VEHICLE_EVIDENCE', 'ASSET_EVIDENCE', 'COMMERCIAL_EVIDENCE', 'PHOTO', 'DOCUMENT'], true)) {
                    $applicationId = $this->applicationId($binding->owner_type, $binding->owner_id)
                        ?? ($binding->owner_type === 'distributor_application' ? $binding->owner_id : null);

                    if ($applicationId !== null) {
                        $isVerifier = DB::table('verification_visits')
                            ->where('application_id', $applicationId)
                            ->where('verifier_id', $actor->id)
                            ->exists();
                        $isCoordinator = DB::table('distributor_applications')
                            ->where('id', $applicationId)
                            ->where('coordinator_id', $actor->id)
                            ->exists();

                        if ($isVerifier || $isCoordinator) {
                            $authorized = true;
                            break;
                        }
                    }
                }

                if ($binding->owner_type === 'verification_visit') {
                    $isVerifierOfVisit = DB::table('verification_visits')
                        ->where('id', $binding->owner_id)
                        ->where('verifier_id', $actor->id)
                        ->exists();
                    if ($isVerifierOfVisit) {
                        $authorized = true;
                        break;
                    }
                }

                if ($binding->owner_type === 'surplus_refund_request' && $binding->purpose === 'REFUND_EVIDENCE') {
                    $isDistributor = DB::table('surplus_refund_requests as refund')
                        ->join('distributor_surpluses as surplus', 'surplus.id', '=', 'refund.surplus_id')
                        ->join('distributors as distributor', 'distributor.id', '=', 'surplus.distributor_id')
                        ->where('refund.id', $binding->owner_id)
                        ->where('distributor.user_id', $actor->id)
                        ->exists();
                    if ($isDistributor) {
                        $authorized = true;
                        break;
                    }
                }

                if ($actor->hasPermissionTo('media.download_branch')) {
                    $branchId = $this->branchId($binding->owner_type, $binding->owner_id);
                    if ($branchId !== null && $actor->hasScopeForBranch($branchId)) {
                        $authorized = true;
                        break;
                    }
                }
            }
        }

        abort_unless($authorized && $media->validation_status === 'VALIDATED', 403);
        abort_unless(Storage::disk($media->disk)->exists($media->path), 404);
        $downloadValues = [
            'file_name' => $media->original_name,
            'mime_type' => $media->mime_type,
            'size_bytes' => $media->size_bytes,
            'purposes' => $bindings->pluck('purpose')->all(),
        ];
        AuditHelper::log('PRIVATE_MEDIA_DOWNLOADED', 'media_file', $media->id, $actor->id, $actor->branch_id, null, $downloadValues, "Descarga de archivo: {$media->original_name}");

        return Storage::disk($media->disk)->download($media->path, $media->original_name, ['Content-Type' => $media->mime_type, 'Cache-Control' => 'private, no-store']);
    }

    private function branchId(string $ownerType, string $ownerId): ?string
    {
        [$table, $column] = match ($ownerType) {
            'verification_visit' => ['verification_visits', 'application_id'],
            'client' => ['client_distributor_assignments', 'branch_id'],
            'payment_clarification' => ['payment_clarifications', 'relation_id'],
            'surplus_refund_request' => ['surplus_refund_requests', 'branch_id'],
            'bank_file_import' => ['bank_file_imports', 'branch_id'],
            'distributor_relation' => ['distributor_relations', 'branch_id'],
            'distributor_application' => ['distributor_applications', 'branch_id'],
            'application_vehicle' => ['application_vehicles', 'application_id'],
            'application_asset_liability' => ['application_assets_liabilities', 'application_id'],
            'application_commercial_credit' => ['application_commercial_credits', 'application_id'],
            default => [null, null],
        };
        if (! $table) {
            return null;
        }
        $query = DB::table($table);
        if ($ownerType === 'client') {
            $query->where('client_id', $ownerId)->whereNull('ends_at');
        } else {
            $query->where('id', $ownerId);
        }
        $value = $query->value($column);
        if ($ownerType === 'payment_clarification' && $value) {
            return DB::table('distributor_relations')->where('id', $value)->value('branch_id');
        }
        if (in_array($ownerType, ['verification_visit', 'application_vehicle', 'application_asset_liability', 'application_commercial_credit'], true) && $value) {
            return DB::table('distributor_applications')->where('id', $value)->value('branch_id');
        }

        return $value;
    }

    private function canonicalExtension(string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg', 'image/pjpeg', 'image/jfif' => 'jpg',
            'image/png', 'image/x-png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/bmp', 'image/x-bmp', 'image/x-ms-bmp' => 'bmp',
            'image/tiff', 'image/tif', 'image/x-tiff' => 'tiff',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            'image/avif' => 'avif',
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip' => 'xlsx',
            default => null,
        };
    }

    private function validarLibroXlsx(UploadedFile $file): void
    {
        $zip = new \ZipArchive;
        $resultado = $zip->open($file->getRealPath());
        $contentTypes = $resultado === true ? $zip->getFromName('[Content_Types].xml') : false;
        $esLibro = $resultado === true
            && is_string($contentTypes)
            && str_contains($contentTypes, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml')
            && $zip->locateName('xl/workbook.xml') !== false
            && $zip->locateName('xl/worksheets/sheet1.xml') !== false;
        if ($resultado === true) {
            $zip->close();
        }

        if (! $esLibro) {
            throw ValidationException::withMessages([
                'file' => ['Archivo inválido. Solo se aceptan archivos Excel XLSX válidos.'],
            ]);
        }
    }

    private function applicationId(string $ownerType, string $ownerId): ?string
    {
        return match ($ownerType) {
            'distributor_application' => DB::table('distributor_applications')->where('id', $ownerId)->value('id'),
            'application_vehicle' => DB::table('application_vehicles')->where('id', $ownerId)->value('application_id'),
            'application_asset_liability' => DB::table('application_assets_liabilities')->where('id', $ownerId)->value('application_id'),
            'application_commercial_credit' => DB::table('application_commercial_credits')->where('id', $ownerId)->value('application_id'),
            default => null,
        };
    }
}
