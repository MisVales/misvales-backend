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
                $query->where(function ($globalQuery) {
                    $globalQuery->where('scope_type', 'GLOBAL')
                        ->whereHas('role', function ($roleQuery) {
                            $roleQuery->whereIn('code', ['general_manager', 'admin']);
                        });
                })->orWhere(function ($branchQuery) use ($branchId) {
                    $branchQuery->where('scope_type', 'BRANCH')
                        ->where('branch_id', $branchId);
                });
            })
            ->exists();
    }

    /**
     * Get active status.
     */
    public function getIsActiveAttribute(): bool
    {
        return $this->state === 'ACTIVE';
    }

    /**
     * Get branch id from active scopes.
     */
    public function getBranchIdAttribute(): ?string
    {
        return $this->roleScopes()
            ->where('status', 'ACTIVE')
            ->whereNotNull('branch_id')
            ->whereNull('revoked_at')
            ->value('branch_id');
    }

    /**
     * Assign role with optional branch_id.
     */
    public function assignRole(string $roleCode, ?string $branchId = null): void
    {
        $role = Role::query()->where('code', $roleCode)->first();

        if ($role === null) {
            return;
        }

        $this->roleScopes()->create([
            'role_id' => $role->id,
            'branch_id' => $branchId,
            'scope_type' => $branchId === null ? 'GLOBAL' : 'BRANCH',
            'assigned_by_user_id' => $this->id,
            'assigned_at' => now(),
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * Check if user has an active role.
     */
    public function hasRole(string $roleCode): bool
    {
        return $this->roleScopes()
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->whereHas('role', fn ($query) => $query->where('code', $roleCode))
            ->exists();
    }
}
