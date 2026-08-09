<?php

namespace App\Modules\Organization\Infrastructure\Persistence\Eloquent;

use App\Modules\Organization\Application\Branches\Queries\BranchListCriteria;
use App\Modules\Organization\Application\Branches\Queries\BranchView;
use App\Modules\Organization\Application\Branches\Queries\PaginatedBranches;
use App\Modules\Organization\Application\Branches\Repositories\BranchReadRepository;
use App\Modules\Organization\Domain\Assignments\EffectiveOrganizationScope;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;

final class EloquentBranchReadRepository implements BranchReadRepository
{
    public function paginate(
        BranchListCriteria $criteria,
        EffectiveOrganizationScope $scope,
    ): PaginatedBranches {
        $query = BranchRecord::query()
            ->withCount(['personnelAssignments as active_personnel_count' => fn ($assignments) => $assignments
                ->where('status', 'ACTIVE')
                ->whereNull('revoked_at')])
            ->when(! $scope->isGlobal(), fn ($query) => $query->whereIn('id', $scope->branchIds()))
            ->when($criteria->status !== null, fn ($query) => $query->where('status', $criteria->status))
            ->when($criteria->search !== null, function ($query) use ($criteria): void {
                $search = '%'.$criteria->search.'%';
                $query->where(fn ($nested) => $nested
                    ->where('code', 'ILIKE', $search)
                    ->orWhere('name', 'ILIKE', $search));
            })
            ->orderBy($criteria->sort, $criteria->direction);

        $paginator = $query->paginate(
            perPage: $criteria->perPage,
            page: $criteria->page,
        );

        $items = $paginator->getCollection()
            ->map(fn (BranchRecord $record): BranchView => new BranchView(
                id: $record->getAttribute('id'),
                code: $record->getAttribute('code'),
                name: $record->getAttribute('name'),
                address: $record->getAttribute('address'),
                isHeadquarters: (bool) $record->getAttribute('is_headquarters'),
                status: $record->getAttribute('status'),
                lockVersion: (int) $record->getAttribute('lock_version'),
                activePersonnelCount: (int) $record->getAttribute('active_personnel_count'),
                createdAt: $record->getAttribute('created_at')->toISOString(),
                updatedAt: $record->getAttribute('updated_at')?->toISOString(),
            ))
            ->all();

        return new PaginatedBranches(
            items: $items,
            page: $paginator->currentPage(),
            perPage: $paginator->perPage(),
            total: $paginator->total(),
            lastPage: $paginator->lastPage(),
        );
    }
}
