<?php

namespace App\Services\Auth;

use App\Models\CoordinatorDistributorAssignment;
use App\Models\Role;
use App\Models\UserRoleScope;
use App\Modules\Organization\Domain\Assignments\Exceptions\CoordinatorHasActiveDependencies;
use App\Modules\Organization\Domain\Assignments\Exceptions\RoleNotAssignable;
use App\Modules\Organization\Domain\Assignments\Exceptions\RoleTransitionNotAllowed;

class RoleTransitionService
{
    private const TRANSITIONS = [
        'coordinator' => ['branch_manager', 'verifier'],
        'verifier' => ['coordinator'],
        'cashier' => ['coordinator', 'verifier'],
    ];

    private const NON_ASSIGNABLE_ROLES = ['distributor', 'general_manager'];

    private const OPERATIONAL_ROLES = [
        'general_manager', 'branch_manager', 'coordinator', 'verifier', 'cashier', 'admin',
    ];

    public function isRoleAssignable(string $roleCode): bool
    {
        return !in_array($roleCode, self::NON_ASSIGNABLE_ROLES, true);
    }

    public function assertRoleAssignable(string $roleCode): void
    {
        if (!$this->isRoleAssignable($roleCode)) {
            throw new RoleNotAssignable($roleCode);
        }
    }

    public function getAvailableTransitions(string $currentRoleCode, string $branchId): array
    {
        $transitions = [];
        $allowedCodes = self::TRANSITIONS[$currentRoleCode] ?? [];

        $roles = Role::whereIn('code', $allowedCodes)->get()->keyBy('code');

        foreach ($allowedCodes as $targetCode) {
            $role = $roles->get($targetCode);
            if (!$role) {
                continue;
            }

            $allowed = true;
            $reason = null;

            if ($targetCode === 'branch_manager') {
                $hasActiveManager = UserRoleScope::where('branch_id', $branchId)
                    ->where('role_id', $role->id)
                    ->where('status', 'ACTIVE')
                    ->whereNull('revoked_at')
                    ->lockForUpdate()
                    ->exists();

                if ($hasActiveManager) {
                    $allowed = false;
                    $reason = 'Esta sucursal ya cuenta con un gerente de sucursal activo.';
                }
            }

            $transitions[] = [
                'role_code' => $targetCode,
                'role_name' => $role->name,
                'allowed' => $allowed,
                'reason' => $reason,
            ];
        }

        return $transitions;
    }

    public function assertTransitionAllowed(string $fromRoleCode, string $toRoleCode): void
    {
        $allowed = self::TRANSITIONS[$fromRoleCode] ?? [];
        if (!in_array($toRoleCode, $allowed, true)) {
            throw new RoleTransitionNotAllowed($fromRoleCode, $toRoleCode);
        }
    }

    public function getCoordinatorActiveDependenciesCount(string $userId): int
    {
        return CoordinatorDistributorAssignment::where('coordinator_id', $userId)
            ->where('status', 'ACTIVE')
            ->whereNull('valid_to')
            ->count();
    }

    public function assertNoCoordinatorDependencies(string $userId): void
    {
        $count = $this->getCoordinatorActiveDependenciesCount($userId);
        if ($count > 0) {
            throw new CoordinatorHasActiveDependencies($userId, $count);
        }
    }

    public function getCurrentOperationalRole(string $userId): ?array
    {
        $scope = UserRoleScope::with(['role', 'branch'])
            ->where('user_id', $userId)
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->whereHas('role', fn($q) => $q->whereIn('code', self::OPERATIONAL_ROLES))
            ->first();

        if (!$scope) {
            return null;
        }

        return [
            'code' => $scope->role->code,
            'name' => $scope->role->name,
            'role_id' => $scope->role->id,
            'branch_id' => $scope->branch_id,
            'branch_name' => $scope->branch ? $scope->branch->name : null,
        ];
    }
}
