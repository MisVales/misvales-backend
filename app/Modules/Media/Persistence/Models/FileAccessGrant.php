<?php

namespace App\Modules\Media\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FileAccessGrant extends Model
{
    use HasUuids;

    protected $table = 'file_access_grants';

    protected $fillable = [
        'file_id',
        'actor_user_id',
        'action_allowed',
        'checked_resource_type',
        'checked_resource_id',
        'expires_at',
        'status',
        'correlation_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
