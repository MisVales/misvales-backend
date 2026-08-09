<?php

namespace App\Models;

use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRoleScope extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'id',
        'user_id',
        'role_id',
        'branch_id',
        'assigned_by_user_id',
        'assigned_at',
        'revoked_by_user_id',
        'revoked_at',
        'assignment_reason',
        'revocation_reason',
        'scope_type',
        'scope_id',
        'status',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (UserRoleScope $assignment): void {
            $assignment->assigned_at ??= now();
            $assignment->scope_type ??= $assignment->branch_id === null ? 'GLOBAL' : 'BRANCH';
            $assignment->status ??= 'ACTIVE';
        });

        static::saving(function (UserRoleScope $assignment): void {
            if ($assignment->revoked_at !== null && $assignment->status === 'ACTIVE') {
                $assignment->status = 'REVOKED';
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(BranchRecord::class, 'branch_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class, 'scope_id');
    }
}
