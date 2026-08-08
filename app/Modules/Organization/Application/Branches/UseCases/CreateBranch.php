<?php

namespace App\Modules\Organization\Application\Branches\UseCases;

use App\Modules\Organization\Application\Events\OrganizationEventPublisher;
use App\Modules\Organization\Domain\Branches\Branch;
use App\Modules\Organization\Domain\Branches\Exceptions\DuplicateBranchCode;
use App\Modules\Organization\Domain\Branches\Repositories\BranchRepository;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchCode;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchName;
use App\Modules\Organization\Domain\Events\OrganizationEvent;
use App\Modules\Organization\Domain\Events\OrganizationEventType;
use Illuminate\Support\Str;

final readonly class CreateBranch
{
    public function __construct(
        private BranchRepository $branches,
        private OrganizationEventPublisher $events,
    ) {}

    public function handle(
        string $id,
        string $code,
        string $name,
        string $actorId,
    ): Branch {
        $branchCode = BranchCode::fromString($code);

        if ($this->branches->findByCode($branchCode) !== null) {
            throw new DuplicateBranchCode($branchCode->value());
        }

        $branch = Branch::create(
            id: BranchId::fromString($id),
            code: $branchCode,
            name: BranchName::fromString($name),
        );

        $this->branches->save($branch, $actorId);
        $this->events->publish(new OrganizationEvent(
            id: Str::uuid()->toString(),
            type: OrganizationEventType::BRANCH_CREATED,
            aggregateType: 'branch',
            aggregateId: $branch->id()->value(),
            actorId: $actorId,
            branchId: $branch->id()->value(),
            details: ['code' => $branch->code()->value(), 'name' => $branch->name()->value()],
        ));

        return $branch;
    }
}
