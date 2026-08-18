<?php

namespace App\Modules\Organization\Infrastructure\Persistence\Eloquent;

use App\Modules\Organization\Application\Personnel\Queries\PaginatedPersonnel;
use App\Modules\Organization\Application\Personnel\Queries\PersonnelListCriteria;
use App\Modules\Organization\Application\Personnel\Queries\PersonnelView;
use App\Modules\Organization\Application\Personnel\Repositories\PersonnelReadRepository;
use App\Modules\Organization\Domain\Assignments\EffectiveOrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class EloquentPersonnelReadRepository implements PersonnelReadRepository
{
    public function paginate(
        PersonnelListCriteria $criteria,
        EffectiveOrganizationScope $scope,
    ): PaginatedPersonnel {
        return $this->runQuery($criteria, $scope);
    }

    public function paginateForBranch(
        string $branchId,
        PersonnelListCriteria $criteria,
        EffectiveOrganizationScope $scope,
    ): PaginatedPersonnel {
        return $this->runQuery(new PersonnelListCriteria(
            page: $criteria->page,
            perPage: $criteria->perPage,
            branchId: $branchId,
            roleId: $criteria->roleId,
            userState: $criteria->userState,
            assignmentStatus: 'ACTIVE',
        ), $scope);
    }

    private function runQuery(
        PersonnelListCriteria $criteria,
        EffectiveOrganizationScope $scope,
    ): PaginatedPersonnel {
        $query = DB::table('user_role_scopes as assignments')
            ->join('users', 'users.id', '=', 'assignments.user_id')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->when(! $scope->isGlobal(), fn ($query) => $query->whereIn('assignments.branch_id', $scope->branchIds()))
            ->when($criteria->branchId !== null, fn ($query) => $query->where(fn ($q) => $q->where('assignments.branch_id', $criteria->branchId)
                ->orWhere(fn ($q2) => $q2->where('assignments.scope_type', 'GLOBAL')
                    ->whereExists(fn ($q3) => $q3->select(DB::raw(1))
                        ->from('branches')
                        ->where('id', $criteria->branchId)
                        ->where('code', 'MATRIZ')
                    )
                )
            ))
            ->when($criteria->roleId !== null, fn ($query) => $query->where('assignments.role_id', $criteria->roleId))
            ->when($criteria->userState !== null, fn ($query) => $query->where('users.state', $criteria->userState))
            ->when($criteria->assignmentStatus !== null, fn ($query) => $query->where('assignments.status', $criteria->assignmentStatus))
            ->select([
                'assignments.id as assignment_id',
                'assignments.branch_id',
                'assignments.scope_type',
                'assignments.status as assignment_status',
                'assignments.assigned_at',
                'assignments.assignment_reason',
                'assignments.revoked_at',
                'assignments.revocation_reason',
                'users.id as user_id',
                'users.name as user_name',
                'users.email as user_email',
                'users.state as user_state',
                'roles.id as role_id',
                'roles.code as role_code',
                'roles.name as role_name',
            ])
            ->orderBy('users.name')
            ->orderBy('roles.code');

        $paginator = $query->paginate(
            perPage: $criteria->perPage,
            page: $criteria->page,
        );

        $items = $paginator->getCollection()
            ->map(fn (object $record): PersonnelView => new PersonnelView(
                assignmentId: $record->assignment_id,
                userId: $record->user_id,
                userName: $record->user_name,
                userEmail: $record->user_email,
                userState: $record->user_state,
                roleId: $record->role_id,
                roleCode: $record->role_code,
                roleName: $record->role_name,
                branchId: $record->branch_id,
                scope: $record->scope_type,
                assignmentStatus: $record->assignment_status,
                assignedAt: CarbonImmutable::parse($record->assigned_at)->toISOString(),
                assignmentReason: $record->assignment_reason,
                revokedAt: $record->revoked_at === null
                    ? null
                    : CarbonImmutable::parse($record->revoked_at)->toISOString(),
                revocationReason: $record->revocation_reason,
            ))
            ->all();

        return new PaginatedPersonnel(
            items: $items,
            page: $paginator->currentPage(),
            perPage: $paginator->perPage(),
            total: $paginator->total(),
            lastPage: $paginator->lastPage(),
        );
    }
}
