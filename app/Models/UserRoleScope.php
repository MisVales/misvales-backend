<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRoleScope extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false; // We use custom timestamp fields in migration

    protected $fillable = [
        'user_id',
        'role_id',
        'branch_id',
        'assigned_by_user_id',
        'assigned_at',
        'revoked_by_user_id',
        'revoked_at',
        'revocation_reason',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

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
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }
}
