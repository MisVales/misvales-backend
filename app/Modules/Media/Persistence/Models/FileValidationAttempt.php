<?php

namespace App\Modules\Media\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FileValidationAttempt extends Model
{
    use HasUuids;

    protected $table = 'file_validation_attempts';

    protected $fillable = [
        'file_id',
        'attempt_number',
        'job_id',
        'started_at',
        'finished_at',
        'detected_mime',
        'size_bytes',
        'sha256',
        'verifications_executed',
        'result',
        'error_code',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'verifications_executed' => 'json',
    ];
}
