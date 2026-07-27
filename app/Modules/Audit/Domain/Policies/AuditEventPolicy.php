<?php

namespace App\Modules\Audit\Domain\Policies;

use App\Models\User;
use App\Modules\Audit\Persistence\Models\AuditEvent;
use Illuminate\Auth\Access\HandlesAuthorization;

class AuditEventPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        // Administradores y Gerentes Generales pueden ver todo
        return in_array($user->role, ['ADMIN', 'GENERAL_MANAGER']);
    }

    public function view(User $user, AuditEvent $auditEvent): bool
    {
        if (in_array($user->role, ['ADMIN', 'GENERAL_MANAGER'])) return true;
        
        // El Gerente de Sucursal solo ve su propia sucursal
        if ($user->role === 'BRANCH_MANAGER' && $user->branch_id === $auditEvent->branch_id) {
            return true;
        }

        return false;
    }
}
