<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ImportacionArchivoBancario extends Model
{
    use HasUuids;

    protected $table = 'bank_file_imports';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['summary' => 'array'];
    }
}
