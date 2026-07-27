<?php

namespace App\Modules\Media\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FileUploadIntent extends Model
{
    use HasUuids;

    protected $table = 'file_upload_intents';

    protected $fillable = [
        'actor_user_id',
        'branch_id',
        'owner_module',
        'owner_type',
        'owner_id',
        'purpose',
        'technical_policy',
        'idempotency_key_hash',
        'status',
        'expires_at',
        'result_file_id',
        'consumed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];
}
