<?php

namespace App\Modules\Organization\Presentation\Http\Resources;

use App\Modules\Organization\Domain\Branches\Branch;

final class BranchResource
{
    /** @return array<string, bool|int|string|null> */
    public static function fromDomain(Branch $branch): array
    {
        return [
            'id' => $branch->id()->value(),
            'code' => $branch->code()->value(),
            'name' => $branch->name()->value(),
            'address' => $branch->address()?->formatted,
            'is_headquarters' => $branch->isHeadquarters(),
            'status' => $branch->status()->value,
            'lock_version' => $branch->lockVersion(),
        ];
    }
}
