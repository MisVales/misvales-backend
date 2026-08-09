<?php

namespace App\Modules\Organization\Application\Branches\UseCases;

use App\Modules\Organization\Application\Events\OrganizationEventPublisher;
use App\Modules\Organization\Domain\Assignments\Exceptions\OrganizationScopeDenied;
use App\Modules\Organization\Domain\Assignments\Services\OrganizationScopeResolver;
use App\Modules\Organization\Domain\Branches\Branch;
use App\Modules\Organization\Domain\Branches\Exceptions\BranchNotFound;
use App\Modules\Organization\Domain\Branches\Exceptions\BranchVersionConflict;
use App\Modules\Organization\Domain\Branches\Repositories\BranchRepository;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;
use App\Modules\Organization\Domain\Events\OrganizationEvent;
use App\Modules\Organization\Domain\Events\OrganizationEventType;
use Illuminate\Support\Str;

final readonly class ActivateBranch
{
    public function __construct(
        private BranchRepository $branches,
        private OrganizationScopeResolver $scopeResolver,
        private OrganizationEventPublisher $events,
    ) {}

    public function handle(string $branchId, int $expectedVersion, string $actorId): Branch
    {
        $branch = $this->branches->find(BranchId::fromString($branchId))
            ?? throw new BranchNotFound($branchId);

        if (! $this->scopeResolver->resolve($actorId)->allows($branch->id()->value())) {
            throw new OrganizationScopeDenied;
        }

        if ($branch->lockVersion() !== $expectedVersion) {
            throw new BranchVersionConflict($branchId, $expectedVersion);
        }

        $branch->activate();

        if ($branch->lockVersion() !== $expectedVersion) {
            $this->branches->save($branch, $actorId, $expectedVersion);
            $this->events->publish(new OrganizationEvent(
                id: Str::uuid()->toString(),
                type: OrganizationEventType::BRANCH_ACTIVATED,
                aggregateType: 'branch',
                aggregateId: $branch->id()->value(),
                actorId: $actorId,
                branchId: $branch->id()->value(),
            ));
        }

        return $branch;
    }
}
