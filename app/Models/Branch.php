<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'code',
        'name',
        'is_headquarters',
        'status',
        'lock_version',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_headquarters' => 'boolean',
        'lock_version' => 'integer',
    ];



    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function personnel(): HasMany
    {
        return $this->hasMany(UserRoleScope::class, 'branch_id');
    }

    public function coordinatorAssignments(): HasMany
    {
        return $this->hasMany(CoordinatorDistributorAssignment::class, 'branch_id');
    }
}
