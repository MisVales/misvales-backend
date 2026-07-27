<?php

namespace App\Modules\Media\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MediaFileBinding extends Model
{
    use HasUuids;

    protected $table = 'media_file_bindings';

    const UPDATED_AT = null; // No updated_at for bindings, they are insert-only historical

    protected $fillable = [
        'file_id',
        'owner_module',
        'owner_type',
        'owner_id',
        'purpose',
        'version_number',
        'is_current',
        'bound_by',
        'bound_at',
        'superseded_by_binding_id',
        'metadata',
    ];

    protected $casts = [
        'bound_at' => 'datetime',
        'is_current' => 'boolean',
        'metadata' => 'json',
    ];
}
