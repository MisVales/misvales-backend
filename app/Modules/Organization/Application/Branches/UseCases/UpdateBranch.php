<?php

namespace App\Modules\Organization\Application\Branches\UseCases;

use App\Modules\Organization\Application\Events\OrganizationEventPublisher;
use App\Modules\Organization\Domain\Assignments\Exceptions\OrganizationScopeDenied;
use App\Modules\Organization\Domain\Assignments\Services\OrganizationScopeResolver;
use App\Modules\Organization\Domain\Branches\Branch;
use App\Modules\Organization\Domain\Branches\Exceptions\BranchNotFound;
use App\Modules\Organization\Domain\Branches\Exceptions\BranchVersionConflict;
use App\Modules\Organization\Domain\Branches\Exceptions\DuplicateBranchCode;
use App\Modules\Organization\Domain\Branches\Repositories\BranchRepository;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchCode;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchName;
use App\Modules\Organization\Domain\Events\OrganizationEvent;
use App\Modules\Organization\Domain\Events\OrganizationEventType;
use Illuminate\Support\Str;

final readonly class UpdateBranch
{
    public function __construct(
        private BranchRepository $branches,
        private OrganizationScopeResolver $scopeResolver,
        private OrganizationEventPublisher $events,
    ) {}

    public function handle(
        string $branchId,
        string $code,
        string $name,
        int $expectedVersion,
        string $actorId,
    ): Branch {
        $branch = $this->branches->find(BranchId::fromString($branchId))
            ?? throw new BranchNotFound($branchId);

        if (! $this->scopeResolver->resolve($actorId)->allows($branch->id()->value())) {
            throw new OrganizationScopeDenied;
        }

        if ($branch->lockVersion() !== $expectedVersion) {
            throw new BranchVersionConflict($branchId, $expectedVersion);
        }

        $newCode = BranchCode::fromString($code);
        $branchWithCode = $this->branches->findByCode($newCode);

        if ($branchWithCode !== null && ! $branchWithCode->id()->equals($branch->id())) {
            throw new DuplicateBranchCode($newCode->value());
        }

        $previous = ['code' => $branch->code()->value(), 'name' => $branch->name()->value()];
        $branch->updateDetails($newCode, BranchName::fromString($name));

        if ($branch->lockVersion() !== $expectedVersion) {
            $this->branches->save($branch, $actorId, $expectedVersion);
            $this->events->publish(new OrganizationEvent(
                id: Str::uuid()->toString(),
                type: OrganizationEventType::BRANCH_UPDATED,
                aggregateType: 'branch',
                aggregateId: $branch->id()->value(),
                actorId: $actorId,
                branchId: $branch->id()->value(),
                details: [
                    'previous' => $previous,
                    'current' => ['code' => $branch->code()->value(), 'name' => $branch->name()->value()],
                ],
            ));
        }

        return $branch;
    }
}
