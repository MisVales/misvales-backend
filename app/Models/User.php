<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Role;

#[Fillable(['name', 'email', 'normalized_email', 'password', 'state', 'webauthn_user_handle'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasUuids;

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

    /**
     * Check if the user has a specific permission via their active roles.
     *
     * @param string $permissionKey
     * @return bool
     */
    public function hasPermissionTo(string $permissionKey): bool
    {
        // Solo usuarios activos pueden ejercer permisos (excepto reglas especiales que podríamos definir luego)
        if ($this->state !== 'ACTIVE') {
            return false;
        }

        return $this->roleScopes()
            ->whereNull('revoked_at')
            ->whereHas('role.permissions', function ($query) use ($permissionKey) {
                $query->where('code', $permissionKey);
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
     * Get branch id from scopes.
     */
    public function getBranchIdAttribute(): ?string
    {
        return $this->roleScopes()->whereNotNull('branch_id')->whereNull('revoked_at')->value('branch_id');
    }

    /**
     * Assign role with optional branch_id (helper for tests and controllers).
     */
    public function assignRole(string $roleCode, ?string $branchId = null): void
    {
        $role = Role::where('code', $roleCode)->first();
        if ($role) {
            $this->roleScopes()->create([
                'role_id' => $role->id,
                'branch_id' => $branchId,
                'scope_type' => $branchId ? 'BRANCH' : 'GLOBAL',
                'assigned_by' => $this->id, // For tests
                'status' => 'ACTIVE'
            ]);
        }
    }
    
    /**
     * Check if user has a role.
     */
    public function hasRole(string $roleCode): bool
    {
        return $this->roleScopes()
            ->whereNull('revoked_at')
            ->whereHas('role', fn ($q) => $q->where('code', $roleCode))
            ->exists();
    }
}
