<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediaFile extends Model
{
    use HasFactory;
    use HasUuids, SoftDeletes;

    protected $table = 'media_files';

    protected $fillable = [
        'file_type',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'sha256',
        'uploaded_by',
        'validation_status',
        'validated_at',
    ];

    protected $casts = ['validated_at' => 'immutable_datetime'];

    protected $hidden = [
        'path',
        'disk',
    ];

    public function bindings()
    {
        return $this->hasMany(MediaFileBinding::class);
    }
}
