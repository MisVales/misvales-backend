<?php

namespace App\Modules\Organization\Infrastructure\Persistence\Eloquent;

use App\Models\UserRoleScope;
use App\Modules\Organization\Domain\Assignments\Exceptions\AssignmentAlreadyClosed;
use App\Modules\Organization\Domain\Assignments\OrganizationAssignment;
use App\Modules\Organization\Domain\Assignments\Repositories\OrganizationAssignmentRepository;
use App\Modules\Organization\Domain\Assignments\ValueObjects\AssignmentId;
use App\Modules\Organization\Domain\Assignments\ValueObjects\OrganizationScope;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class EloquentOrganizationAssignmentRepository implements OrganizationAssignmentRepository
{
    public function __construct(private OrganizationAssignmentMapper $mapper) {}

    public function find(AssignmentId $id): ?OrganizationAssignment
    {
        $record = UserRoleScope::query()->find($id->value());

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function historyForUser(string $userId): array
    {
        return UserRoleScope::query()
            ->where('user_id', $userId)
            ->latest('assigned_at')
            ->get()
            ->map(fn (UserRoleScope $record): OrganizationAssignment => $this->mapper->toDomain($record))
            ->all();
    }

    public function activeForUserAndRole(string $userId, string $roleId): array
    {
        return $this->activeQuery()
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->oldest('assigned_at')
            ->get()
            ->map(fn (UserRoleScope $record): OrganizationAssignment => $this->mapper->toDomain($record))
            ->all();
    }

    public function hasActiveDuplicate(
        string $userId,
        string $roleId,
        ?BranchId $branchId,
        OrganizationScope $scope,
    ): bool {
        return $this->activeQuery()
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->where('scope_type', $scope->value)
            ->when(
                $branchId === null,
                fn (Builder $query) => $query->whereNull('branch_id'),
                fn (Builder $query) => $query->where('branch_id', $branchId->value()),
            )
            ->exists();
    }

    public function hasActiveAssignmentsInBranch(BranchId $branchId): bool
    {
        return $this->activeQuery()
            ->where('branch_id', $branchId->value())
            ->exists();
    }

    public function save(OrganizationAssignment $assignment): void
    {
        UserRoleScope::query()->create($this->mapper->toPersistence($assignment));
    }

    public function updateDetails(OrganizationAssignment $assignment): void
    {
        $attributes = $this->mapper->toPersistence($assignment);

        $updated = $this->activeQuery()
            ->whereKey($assignment->id()->value())
            ->update([
                'assigned_at' => $attributes['assigned_at'],
                'assignment_reason' => $attributes['assignment_reason'],
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new AssignmentAlreadyClosed($assignment->id()->value());
        }
    }

    public function close(OrganizationAssignment $assignment): void
    {
        $attributes = $this->mapper->toPersistence($assignment);

        $updated = $this->activeQuery()
            ->whereKey($assignment->id()->value())
            ->update([
                'status' => $attributes['status'],
                'revoked_by_user_id' => $attributes['revoked_by_user_id'],
                'revoked_at' => $attributes['revoked_at'],
                'revocation_reason' => $attributes['revocation_reason'],
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new AssignmentAlreadyClosed($assignment->id()->value());
        }
    }

    public function replace(array $assignmentsToClose, OrganizationAssignment $newAssignment): void
    {
        DB::transaction(function () use ($assignmentsToClose, $newAssignment): void {
            foreach ($assignmentsToClose as $assignment) {
                $this->close($assignment);
            }

            $this->save($newAssignment);
        });
    }

    /** @return Builder<UserRoleScope> */
    private function activeQuery(): Builder
    {
        return UserRoleScope::query()
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at');
    }
}
