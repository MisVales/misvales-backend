<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    /** @var list<string> */
    protected $fillable = ['code', 'name', 'is_active'];

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }

    protected function casts(): array
    {
        return ['code' => PermissionCode::class, 'is_active' => 'boolean'];
    }
}
