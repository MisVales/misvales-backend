<?php

namespace App\Modules\Access\Application\Services;

use App\Models\User;
use App\Modules\Access\Domain\Contracts\OrganizationContextInvalidatorInterface;
use Illuminate\Support\Facades\Log;

class OrganizationContextInvalidatorService implements OrganizationContextInvalidatorInterface
{
    public function invalidateForUser(int $userId, string $reason): void
    {
        User::where('id', $userId)->increment('context_version');
        Log::info("SECURITY AUDIT: Contexto invalidado para el usuario ID {$userId}. Razón: {$reason}");
    }

    public function invalidateForRole(int $roleId, string $reason): void
    {
        User::where('role_id', $roleId)->increment('context_version');
        Log::info("SECURITY AUDIT: Contexto invalidado para usuarios con Rol ID {$roleId}. Razón: {$reason}");
    }

    public function invalidateForBranch(int $branchId, string $reason): void
    {
        User::where('branch_id', $branchId)->increment('context_version');
        Log::info("SECURITY AUDIT: Contexto invalidado para usuarios en la Sucursal ID {$branchId}. Razón: {$reason}");
    }
}