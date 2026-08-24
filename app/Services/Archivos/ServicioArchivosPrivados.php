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
        'PHOTO' => ['jpg', 'jpeg', 'png', 'webp'],
        'IDENTIFICATION' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
        'ADDRESS_PROOF' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
        'VEHICLE_EVIDENCE' => ['jpg', 'jpeg', 'jfif', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff', 'heic', 'heif', 'pdf'],
        'ASSET_EVIDENCE' => ['jpg', 'jpeg', 'jfif', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff', 'heic', 'heif', 'pdf'],
        'COMMERCIAL_EVIDENCE' => ['jpg', 'jpeg', 'jfif', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff', 'heic', 'heif', 'pdf'],
        'RECEIPT' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
        'CLARIFICATION' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
        'REFUND_EVIDENCE' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
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
        $requiresMatchingClientExtension = $extension === 'xlsx';
        if ($extension === null || ! in_array($extension, self::PURPOSES[$purpose], true) || ($requiresMatchingClientExtension && $clientExtension !== 'xlsx') || $file->getSize() <= 0 || $file->getSize() > 15 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => ['Extensión, MIME real o tamaño inválido.']]);
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
            AuditHelper::log('PRIVATE_MEDIA_STORED', 'media_file', $media->id, $actor->id, $actor->branch_id, null, ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'purpose' => $purpose, 'sha256' => $hash, 'size_bytes' => $file->getSize()]);

            return $media;
        }, 3);
    }

    public function descargar(MediaFile $media, User $actor): StreamedResponse
    {
        $binding = $media->bindings()->firstOrFail();
        $authorized = $actor->hasPermissionTo('media.download_global') || $binding->created_by === $actor->id || $media->uploaded_by === $actor->id;
        if (! $authorized && in_array($binding->purpose, ['IDENTIFICATION', 'ADDRESS_PROOF', 'VEHICLE_EVIDENCE', 'ASSET_EVIDENCE', 'COMMERCIAL_EVIDENCE'], true)) {
            $applicationId = $this->applicationId($binding->owner_type, $binding->owner_id);
            $authorized = $applicationId !== null && DB::table('verification_visits')
                ->where('application_id', $applicationId)
                ->where('verifier_id', $actor->id)
                ->exists();
            if (! $authorized && $applicationId !== null) {
                $authorized = DB::table('distributor_applications')
                    ->where('id', $applicationId)
                    ->where('coordinator_id', $actor->id)
                    ->exists();
            }
        }
        if (! $authorized && $binding->owner_type === 'surplus_refund_request' && $binding->purpose === 'REFUND_EVIDENCE') {
            $authorized = DB::table('surplus_refund_requests as refund')
                ->join('distributor_surpluses as surplus', 'surplus.id', '=', 'refund.surplus_id')
                ->join('distributors as distributor', 'distributor.id', '=', 'surplus.distributor_id')
                ->where('refund.id', $binding->owner_id)
                ->where('distributor.user_id', $actor->id)
                ->exists();
        }
        if (! $authorized && $actor->hasPermissionTo('media.download_branch')) {
            $authorized = $this->branchId($binding->owner_type, $binding->owner_id) !== null && $actor->hasScopeForBranch($this->branchId($binding->owner_type, $binding->owner_id));
        }
        abort_unless($authorized && $media->validation_status === 'VALIDATED', 403);
        abort_unless(Storage::disk($media->disk)->exists($media->path), 404);
        AuditHelper::log('PRIVATE_MEDIA_DOWNLOADED', 'media_file', $media->id, $actor->id, $actor->branch_id, null, ['purpose' => $binding->purpose]);

        return Storage::disk($media->disk)->download($media->path, $media->original_name, ['Content-Type' => $media->mime_type, 'Cache-Control' => 'private, no-store']);
    }

    private function branchId(string $ownerType, string $ownerId): ?string
    {
        [$table, $column] = match ($ownerType) {
            'verification_visit' => ['verification_visits', 'branch_id'],
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
        if (in_array($ownerType, ['application_vehicle', 'application_asset_liability', 'application_commercial_credit'], true) && $value) {
            return DB::table('distributor_applications')->where('id', $value)->value('branch_id');
        }

        return $value;
    }

    private function canonicalExtension(string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg', 'image/pjpeg' => 'jpg',
            'image/png', 'image/x-png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/bmp', 'image/x-bmp', 'image/x-ms-bmp' => 'bmp',
            'image/tiff' => 'tiff',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip' => 'xlsx',
            default => null,
        };
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
