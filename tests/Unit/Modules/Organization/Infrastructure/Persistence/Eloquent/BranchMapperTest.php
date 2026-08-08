<?php

namespace Tests\Unit\Modules\Organization\Infrastructure\Persistence\Eloquent;

use App\Modules\Organization\Domain\Branches\Branch;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchCode;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchName;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchStatus;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\BranchMapper;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;
use PHPUnit\Framework\TestCase;

final class BranchMapperTest extends TestCase
{
    public function test_it_maps_an_eloquent_record_to_the_domain(): void
    {
        $record = new BranchRecord;
        $record->forceFill([
            'id' => '019fcbec-4ba4-7721-bf39-c9729fb0bd67',
            'code' => 'TOR-MATRIZ',
            'name' => 'Sucursal Matriz Torreón',
            'is_headquarters' => true,
            'status' => 'ACTIVE',
            'lock_version' => 3,
        ]);

        $branch = (new BranchMapper)->toDomain($record);

        self::assertSame('019fcbec-4ba4-7721-bf39-c9729fb0bd67', $branch->id()->value());
        self::assertSame('TOR-MATRIZ', $branch->code()->value());
        self::assertSame('Sucursal Matriz Torreón', $branch->name()->value());
        self::assertTrue($branch->isHeadquarters());
        self::assertSame(BranchStatus::ACTIVE, $branch->status());
        self::assertSame(3, $branch->lockVersion());
    }

    public function test_it_maps_the_domain_to_persistence_attributes(): void
    {
        $branch = Branch::reconstitute(
            id: BranchId::fromString('019fcbec-4ba4-7721-bf39-c9729fb0bd67'),
            code: BranchCode::fromString('TRC-02'),
            name: BranchName::fromString('Sucursal Torreón Norte'),
            headquarters: false,
            status: BranchStatus::INACTIVE,
            lockVersion: 2,
        );

        self::assertSame([
            'id' => '019fcbec-4ba4-7721-bf39-c9729fb0bd67',
            'code' => 'TRC-02',
            'name' => 'Sucursal Torreón Norte',
            'is_headquarters' => false,
            'status' => 'INACTIVE',
            'lock_version' => 2,
        ], (new BranchMapper)->toPersistence($branch));
    }
}
