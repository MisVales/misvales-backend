<?php

namespace App\Policies;

use App\Models\CoordinatorDistributorAssignment;
use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;

class CoordinatorDistributorAssignmentPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        $role = $this->getUserRoleCode($user);

        if ($role === 'GENERAL_MANAGER') {
            return true;
        }

        return null;
    }

    public function view(User $user, CoordinatorDistributorAssignment $assignment): bool
    {
        $role = $this->getUserRoleCode($user);

        if ($role === 'ADMINISTRATOR') {
            return true;
        }

        if ($role === 'BRANCH_MANAGER') {
            return $user->branch_id === $assignment->branch_id;
        }

        if ($role === 'COORDINATOR') {
            return $user->id === $assignment->coordinator_user_id;
        }

        return false;
    }

    public function delete(User $user, CoordinatorDistributorAssignment $assignment): bool
    {
        $role = $this->getUserRoleCode($user);

        if ($role === 'ADMINISTRATOR') {
            return false;
        }

        if ($role === 'BRANCH_MANAGER') {
            return $user->branch_id === $assignment->branch_id;
        }

        return false;
    }

    public function create(User $user, $branch = null): bool
    {
        $role = $this->getUserRoleCode($user);

        if ($role === 'GENERAL_MANAGER') {
            return true;
        }

        if ($role === 'ADMINISTRATOR') {
            return false;
        }

        if ($role === 'BRANCH_MANAGER') {
            // Si la sucursal viene como objeto, validamos por id
            if (is_object($branch) && isset($branch->id)) {
                return $user->branch_id === $branch->id;
            }

            return true;
        }

        return false;
    }

    private function getUserRoleCode(User $user): string
    {
        $roleCode = null;

        // Intentar obtener a través de la relación cargada
        if ($user->relationLoaded('role') && $user->role) {
            $roleCode = $user->role->code;
        }

        // Si no está cargada pero tiene ID, consultarlo directamente
        if (! $roleCode && $user->role_id) {
            $role = Role::find($user->role_id);
            $roleCode = $role ? $role->code : null;
        }

        if (! $roleCode) {
            return '';
        }

        // Manejar tanto string plano como Value Objects / Enums de dominio
        if (is_object($roleCode)) {
            if (property_exists($roleCode, 'value')) {
                return (string) $roleCode->value;
            }
            if (method_exists($roleCode, 'value')) {
                return (string) $roleCode->value();
            }

            return (string) $roleCode;
        }

        return (string) $roleCode;
    }
}
