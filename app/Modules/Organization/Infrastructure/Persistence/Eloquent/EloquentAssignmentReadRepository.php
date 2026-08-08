<?php

namespace App\Modules\Organization\Infrastructure\Persistence\Eloquent;

use App\Models\UserRoleScope;
use App\Modules\Organization\Application\Assignments\Repositories\AssignmentReadRepository;
use App\Modules\Organization\Domain\Assignments\EffectiveOrganizationScope;

final class EloquentAssignmentReadRepository implements AssignmentReadRepository
{
    public function forUser(
        string $userId,
        bool $includeHistory,
        ?EffectiveOrganizationScope $scope = null,
    ): array {
        $query = UserRoleScope::query()
            ->with(['role', 'assignedBy'])
            ->where('user_id', $userId)
            ->when(
                $scope !== null && ! $scope->isGlobal(),
                fn ($query) => $query->whereIn('branch_id', $scope->branchIds()),
            );

        if (! $includeHistory) {
            // Contrato heredado del Módulo 1: solo asignaciones activas.
            $query->whereNull('revoked_at');
        } else {
            $query->latest('assigned_at');
        }

        return $query->get()->toArray();
    }
}
