<?php

namespace App\Modules\Media\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MediaFile extends Model
{
    use HasUuids;

    protected $table = 'media_files';

    protected $fillable = [
        'public_number',
        'file_type',
        'status',
        'storage_disk',
        'storage_key',
        'temporary_storage_key',
        'original_name',
        'safe_display_name',
        'declared_extension',
        'detected_extension',
        'declared_mime',
        'detected_mime',
        'size_bytes',
        'sha256',
        'uploaded_by',
        'branch_id',
        'validated_at',
        'validated_by_process',
        'rejection_code',
        'rejection_detail',
        'available_at',
    ];

    protected $casts = [
        'validated_at' => 'datetime',
        'available_at' => 'datetime',
    ];
}
