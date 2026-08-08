<?php

namespace App\Models;

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
        'assigned_by',
        'valid_from',
        'ended_by',
        'valid_to',
        'reason',
        'scope_type',
        'status',
    ];

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
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

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }
}
