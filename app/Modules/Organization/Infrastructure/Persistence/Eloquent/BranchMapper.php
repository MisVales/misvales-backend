<?php

namespace App\Modules\Organization\Infrastructure\Persistence\Eloquent;

use App\Modules\Organization\Domain\Branches\Branch;
use App\Modules\Organization\Domain\Branches\ValidatedAddress;
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
            address: $record->getAttribute('address') === null ? null : new ValidatedAddress(
                formatted: $record->getAttribute('address'),
                validationId: $record->getAttribute('address_validation_id'),
                placeId: $record->getAttribute('address_place_id'),
                latitude: $record->getAttribute('address_latitude') === null ? null : (float) $record->getAttribute('address_latitude'),
                longitude: $record->getAttribute('address_longitude') === null ? null : (float) $record->getAttribute('address_longitude'),
            ),
            headquarters: (bool) $record->getAttribute('is_headquarters'),
            status: BranchStatus::fromString($record->getAttribute('status')),
            lockVersion: (int) $record->getAttribute('lock_version'),
        );
    }

    /** @return array<string, bool|float|int|string|null> */
    public function toPersistence(Branch $branch): array
    {
        return [
            'id' => $branch->id()->value(),
            'code' => $branch->code()->value(),
            'name' => $branch->name()->value(),
            'address' => $branch->address()?->formatted,
            'address_validation_id' => $branch->address()?->validationId,
            'address_place_id' => $branch->address()?->placeId,
            'address_latitude' => $branch->address()?->latitude,
            'address_longitude' => $branch->address()?->longitude,
            'address_validated_at' => $branch->address() === null ? null : now(),
            'is_headquarters' => $branch->isHeadquarters(),
            'status' => $branch->status()->value,
            'lock_version' => $branch->lockVersion(),
        ];
    }
}
