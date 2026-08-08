<?php

namespace App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BranchRecord extends Model
{
    use HasUuids;

    protected $table = 'branches';

    protected $fillable = [
        'id',
        'code',
        'name',
        'is_headquarters',
        'status',
        'lock_version',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_headquarters' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
