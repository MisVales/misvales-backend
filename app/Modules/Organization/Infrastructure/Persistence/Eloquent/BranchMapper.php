<?php

namespace App\Modules\Organization\Infrastructure\Persistence\Eloquent;

use App\Modules\Organization\Domain\Branches\Branch;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchCode;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchName;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchStatus;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;

final class BranchMapper
{
    public function toDomain(BranchRecord $record): Branch
    {
        return Branch::reconstitute(
            id: BranchId::fromString($record->getAttribute('id')),
            code: BranchCode::fromString($record->getAttribute('code')),
            name: BranchName::fromString($record->getAttribute('name')),
            headquarters: (bool) $record->getAttribute('is_headquarters'),
            status: BranchStatus::fromString($record->getAttribute('status')),
            lockVersion: (int) $record->getAttribute('lock_version'),
        );
    }

    /** @return array<string, bool|int|string> */
    public function toPersistence(Branch $branch): array
    {
        return [
            'id' => $branch->id()->value(),
            'code' => $branch->code()->value(),
            'name' => $branch->name()->value(),
            'is_headquarters' => $branch->isHeadquarters(),
            'status' => $branch->status()->value,
            'lock_version' => $branch->lockVersion(),
        ];
    }
}
