<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'normalized_email', 'password', 'state', 'webauthn_user_handle'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $appends = ['is_active', 'branch_id'];

    public function getIsActiveAttribute(): bool
    {
        return $this->state === 'ACTIVE';
    }

    public function getBranchIdAttribute(): ?string
    {
        if ($this->relationLoaded('roleScopes')) {
            return $this->roleScopes
                ->first(fn (UserRoleScope $scope): bool => $scope->status === 'ACTIVE'
                    && $scope->revoked_at === null
                    && $scope->branch_id !== null)
                ?->branch_id;
        }

        return $this->roleScopes()
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->whereNotNull('branch_id')
            ->value('branch_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'disabled_at' => 'datetime',
            'mfa_enrolled_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    /**
     * Scopes/Roles assigned to the user.
     */
    public function roleScopes(): HasMany
    {
        return $this->hasMany(UserRoleScope::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role_scopes')
            ->withPivot(['branch_id', 'scope_type', 'scope_id', 'status', 'assigned_at', 'revoked_at']);
    }

    public function hasRole(string $roleCode, ?string $branchId = null): bool
    {
        return $this->roleScopes()
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->whereHas('role', fn ($query) => $query->where('code', $roleCode))
            ->exists();
    }

    public function assignRole(string $roleCode, ?string $branchId = null): UserRoleScope
    {
        $role = Role::query()->where('code', $roleCode)->firstOrFail();
        $scopeType = $branchId === null ? 'GLOBAL' : 'BRANCH';

        if ($branchId !== null && ! Branch::query()->whereKey($branchId)->exists()) {
            $branch = new Branch;
            $branch->forceFill([
                'id' => $branchId,
                'code' => 'AUTO-'.strtoupper(substr(str_replace('-', '', $branchId), 0, 12)),
                'name' => 'Sucursal de compatibilidad '.substr($branchId, 0, 8),
                'is_headquarters' => false,
                'status' => 'ACTIVE',
                'lock_version' => 0,
                'created_by' => $this->id,
            ])->save();
        }

        return $this->roleScopes()->firstOrCreate([
            'role_id' => $role->id,
            'branch_id' => $branchId,
            'scope_type' => $scopeType,
            'status' => 'ACTIVE',
        ], [
            'assigned_by_user_id' => $this->id,
            'assigned_at' => now(),
        ]);
    }

    public function distribuidora(): HasOne
    {
        return $this->hasOne(Distribuidora::class, 'user_id');
    }

    /**
     * Check if the user has a specific permission via their active roles.
     */
    public function hasPermissionTo(string $permissionKey): bool
    {
        // Solo usuarios activos pueden ejercer permisos (excepto reglas especiales que podríamos definir luego)
        if ($this->state !== 'ACTIVE') {
            return false;
        }

        return $this->roleScopes()
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->whereHas('role.permissions', function ($query) use ($permissionKey) {
                $query->where('code', $permissionKey);
            })
            ->exists();
    }

    /**
     * Check if the user has jurisdiction over a specific branch.
     * General Managers have GLOBAL scope, Branch Managers have BRANCH scope.
     */
    public function hasScopeForBranch(string $branchId): bool
    {
        if ($this->state !== 'ACTIVE') {
            return false;
        }

        return $this->roleScopes()
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->where(function ($query) use ($branchId) {
                $query->where('scope_type', 'GLOBAL')
                    ->orWhere('branch_id', $branchId);
            })
            ->exists();
    }
}
