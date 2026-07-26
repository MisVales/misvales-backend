<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Presentation\Http\Policies;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Mobility\Infrastructure\Persistence\Models\AdministrativeReassignment;
use App\Modules\Mobility\Infrastructure\Persistence\Models\CoordinatorReassignmentBatch;
use App\Modules\Mobility\Infrastructure\Persistence\Models\DistributorBranchChange;

/** La autorización detallada de alcance se revalida además dentro de cada transacción. */
final class ManagerMobilityPolicy
{
    public function view(User $actor, AdministrativeReassignment|DistributorBranchChange|CoordinatorReassignmentBatch $process): bool
    {
        if (in_array($actor->role_code, [RoleCode::GENERAL_MANAGER->value, RoleCode::ADMINISTRATOR->value], true)) {
            return true;
        }

        $branchId = $process instanceof AdministrativeReassignment
            ? $process->scope_branch_id
            : $process->branch_id ?? $process->origin_branch_id;

        return $actor->role_code === RoleCode::SUCURSAL_MANAGER->value
            && $actor->branch_id === $branchId;
    }
}
