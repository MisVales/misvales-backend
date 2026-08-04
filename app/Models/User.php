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

#[Fillable(['name', 'email', 'normalized_email', 'password', 'state', 'webauthn_user_handle'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

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
     */
    public function hasPermissionTo(string $permissionKey): bool
    {
        // Solo usuarios activos pueden ejercer permisos (excepto reglas especiales que podríamos definir luego)
        if ($this->state !== 'ACTIVE') {
            return false;
        }

        return $this->roleScopes()
            ->where('status', 'ACTIVE')
            ->whereNull('valid_to')
            ->whereHas('role.permissions', function ($query) use ($permissionKey) {
                $query->where('code', $permissionKey);
            })
            ->exists();
    }
}
