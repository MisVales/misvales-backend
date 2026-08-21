<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ImportacionArchivoBancario extends Model
{
    use HasUuids;

    protected $table = 'bank_file_imports';

    protected $fillable = [
        'private_path',
        'original_name',
        'file_size',
        'file_hash',
        'uploaded_by',
        'branch_id',
        'status',
        'row_count',
        'summary',
        'error',
        'processed_at',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return ['summary' => 'array', 'processed_at' => 'immutable_datetime'];
    }
}
