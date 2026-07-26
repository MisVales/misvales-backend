<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Services;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Reporting\Domain\Enums\ReportScopeType;
use App\Modules\Reporting\Domain\Exceptions\ReportingException;
use App\Modules\Reporting\Domain\ValueObjects\ReportScope;

final class ReportScopeResolver
{
    public function resolve(User $actor, RoleCode $role): ReportScope
    {
        return match ($role) {
            RoleCode::GENERAL_MANAGER, RoleCode::ADMINISTRATOR => new ReportScope(ReportScopeType::GLOBAL),
            RoleCode::SUCURSAL_MANAGER => new ReportScope(
                ReportScopeType::BRANCH,
                branchId: $actor->branch_public_id ?? throw ReportingException::scopeDenied(),
            ),
            RoleCode::COORDINATOR => new ReportScope(
                ReportScopeType::COORDINATOR,
                branchId: $actor->branch_public_id ?? throw ReportingException::scopeDenied(),
                coordinatorId: $actor->public_id,
            ),
            RoleCode::DISTRIBUTOR => new ReportScope(
                ReportScopeType::DISTRIBUTOR,
                distributorId: $actor->public_id,
            ),
            default => throw ReportingException::accessDenied(),
        };
    }

    /** @param array<string, mixed> $filters */
    public function assertFiltersCannotExpand(ReportScope $scope, array $filters): void
    {
        $expected = [
            'branch_id' => $scope->branchId,
            'coordinator_id' => $scope->coordinatorId,
            'distributor_id' => $scope->distributorId,
        ];
        foreach ($expected as $key => $value) {
            if ($value !== null && isset($filters[$key]) && $filters[$key] !== $value) {
                throw ReportingException::scopeDenied();
            }
        }
        if ($scope->type === ReportScopeType::DISTRIBUTOR
            && (isset($filters['branch_id']) || isset($filters['coordinator_id']))) {
            throw ReportingException::scopeDenied();
        }
    }
}
