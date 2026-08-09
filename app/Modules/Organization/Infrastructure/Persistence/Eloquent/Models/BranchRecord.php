<?php

namespace App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models;

use App\Models\User;
use App\Models\UserRoleScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BranchRecord extends Model
{
    use HasUuids;

    protected $table = 'branches';

    protected $fillable = [
        'id',
        'code',
        'name',
        'address',
        'address_validation_id',
        'address_place_id',
        'address_latitude',
        'address_longitude',
        'address_validated_at',
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
            'address_latitude' => 'decimal:7',
            'address_longitude' => 'decimal:7',
            'address_validated_at' => 'immutable_datetime',
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

    public function personnelAssignments(): HasMany
    {
        return $this->hasMany(UserRoleScope::class, 'branch_id');
    }
}
