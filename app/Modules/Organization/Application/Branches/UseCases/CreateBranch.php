<?php

namespace App\Modules\Organization\Application\Branches\UseCases;

use App\Modules\Organization\Application\Events\OrganizationEventPublisher;
use App\Modules\Organization\Domain\Branches\Branch;
use App\Modules\Organization\Domain\Branches\Repositories\BranchRepository;
use App\Modules\Organization\Domain\Branches\ValidatedAddress;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchCode;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchName;
use App\Modules\Organization\Domain\Events\OrganizationEvent;
use App\Modules\Organization\Domain\Events\OrganizationEventType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreateBranch
{
    public function __construct(
        private BranchRepository $branches,
        private OrganizationEventPublisher $events,
    ) {}

    public function handle(
        string $id,
        string $name,
        string $address,
        ?float $lat,
        ?float $lng,
        string $actorId,
    ): Branch {
        $branchCode = BranchCode::fromString(sprintf(
            'SUC-%03d',
            (int) DB::scalar('SELECT NEXT VALUE FOR branches_code_sequence'),
        ));
        $validatedAddress = new ValidatedAddress(
            formatted: $address,
            validationId: 'sepomex_geoapify',
            placeId: null,
            latitude: $lat,
            longitude: $lng
        );

        $branch = Branch::create(
            id: BranchId::fromString($id),
            code: $branchCode,
            name: BranchName::fromString($name),
            address: $validatedAddress,
        );

        $this->branches->save($branch, $actorId);
        $this->events->publish(new OrganizationEvent(
            id: Str::uuid()->toString(),
            type: OrganizationEventType::BRANCH_CREATED,
            aggregateType: 'branch',
            aggregateId: $branch->id()->value(),
            actorId: $actorId,
            branchId: $branch->id()->value(),
            details: [
                'code' => $branch->code()->value(),
                'name' => $branch->name()->value(),
                'address' => $branch->address()?->formatted,
            ],
        ));

        return $branch;
    }
}
