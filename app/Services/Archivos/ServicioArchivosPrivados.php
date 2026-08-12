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
        'IDENTIFICATION' => ['jpg', 'jpeg', 'png', 'pdf'],
        'RECEIPT' => ['jpg', 'jpeg', 'png', 'pdf'],
        'CLARIFICATION' => ['jpg', 'jpeg', 'png', 'pdf'],
        'REFUND_EVIDENCE' => ['jpg', 'jpeg', 'png', 'pdf'],
        'BANK_XLSX' => ['xlsx'],
        'GENERATED_DOCUMENT' => ['pdf'],
    ];

    private const MIME = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    ];

    private const OWNERS = ['verification_visit', 'client', 'payment_clarification', 'surplus_refund_request', 'bank_file_import', 'distributor_relation'];

    public function guardar(UploadedFile $file, string $ownerType, string $ownerId, string $purpose, User $actor): MediaFile
    {
        abort_unless($actor->hasPermissionTo('media.upload'), 403);
        $purpose = strtoupper($purpose);
        if (! in_array($ownerType, self::OWNERS, true) || ! isset(self::PURPOSES[$purpose])) {
            throw ValidationException::withMessages(['context' => ['Contexto o propósito de archivo no permitido.']]);
        }
        $branchId = $this->branchId($ownerType, $ownerId);
        if (! $branchId) {
            throw ValidationException::withMessages(['owner_id' => ['La entidad propietaria no existe.']]);
        }
        $ownsClient = $ownerType === 'client' && DB::table('client_distributor_assignments as a')->join('distributors as d', 'd.id', '=', 'a.distributor_id')->where('a.client_id', $ownerId)->whereNull('a.ends_at')->where('d.user_id', $actor->id)->exists();
        abort_unless($actor->hasPermissionTo('media.download_global') || $actor->hasScopeForBranch($branchId) || $ownsClient, 403);
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        if (! in_array($extension, self::PURPOSES[$purpose], true) || ! in_array($mime, self::MIME[$extension] ?? [], true) || $file->getSize() <= 0 || $file->getSize() > 15 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => ['Extensión, MIME real o tamaño inválido.']]);
        }
        $hash = hash_file('sha256', $file->getRealPath());

        return DB::transaction(function () use ($file, $ownerType, $ownerId, $purpose, $actor, $extension, $mime, $hash): MediaFile {
            $temporary = 'tmp/'.Str::uuid();
            Storage::disk('private')->putFileAs('tmp', $file, basename($temporary));
            $destination = 'media/'.now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
            if (! Storage::disk('private')->move($temporary, $destination)) {
                throw ValidationException::withMessages(['file' => ['No fue posible finalizar el almacenamiento privado.']]);
            }
            $media = MediaFile::query()->create(['file_type' => $purpose, 'disk' => 'private', 'path' => $destination, 'original_name' => basename($file->getClientOriginalName()), 'mime_type' => $mime, 'size_bytes' => $file->getSize(), 'sha256' => $hash, 'uploaded_by' => $actor->id, 'validation_status' => 'VALIDATED', 'validated_at' => now()]);
            MediaFileBinding::query()->create(['media_file_id' => $media->id, 'owner_type' => $ownerType, 'owner_id' => $ownerId, 'purpose' => $purpose, 'created_by' => $actor->id]);
            AuditHelper::log('PRIVATE_MEDIA_STORED', 'media_file', $media->id, $actor->id, $actor->branch_id, null, ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'purpose' => $purpose, 'sha256' => $hash, 'size_bytes' => $file->getSize()]);

            return $media;
        }, 3);
    }

    public function descargar(MediaFile $media, User $actor): StreamedResponse
    {
        $binding = $media->bindings()->firstOrFail();
        $authorized = $actor->hasPermissionTo('media.download_global') || $binding->created_by === $actor->id || $media->uploaded_by === $actor->id;
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

        return $value;
    }
}
