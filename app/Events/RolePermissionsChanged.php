<?php

namespace App\Events;

use App\Modules\Access\Infrastructure\Persistence\Models\Role;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RolePermissionsChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Role $role;
    public array $permissions;
    public string $reason;

    public function __construct(Role $role, array $permissions, string $reason)
    {
        $this->role = $role;
        $this->permissions = $permissions;
        $this->reason = $reason;
    }
}