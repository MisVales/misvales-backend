<?php

namespace Tests\Unit\Modules\Organization\Infrastructure\Seeders;

use App\Modules\Organization\Domain\Branches\Branch;
use App\Modules\Organization\Domain\Branches\Repositories\BranchRepository;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchCode;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchName;
use Database\Seeders\HeadquartersBranchSeeder;
use PHPUnit\Framework\TestCase;

final class HeadquartersBranchSeederTest extends TestCase
{
    public function test_it_does_nothing_when_headquarters_already_exists(): void
    {
        $repository = new InMemoryBranchRepository($this->headquarters());

        (new HeadquartersBranchSeeder($repository))->run();

        self::assertSame(0, $repository->saveCalls);
    }

    private function headquarters(): Branch
    {
        return Branch::create(
            id: BranchId::fromString('019fcbec-4ba4-7721-bf39-c9729fb0bd67'),
            code: BranchCode::fromString('TOR-MATRIZ'),
            name: BranchName::fromString('Sucursal Matriz Torreón'),
            headquarters: true,
        );
    }
}

final class InMemoryBranchRepository implements BranchRepository
{
    public int $saveCalls = 0;

    public function __construct(private ?Branch $headquarters) {}

    public function find(BranchId $id): ?Branch
    {
        return $this->headquarters?->id()->equals($id) === true ? $this->headquarters : null;
    }

    public function findByCode(BranchCode $code): ?Branch
    {
        return $this->headquarters?->code()->equals($code) === true ? $this->headquarters : null;
    }

    public function headquarters(): ?Branch
    {
        return $this->headquarters;
    }

    public function save(Branch $branch, string $actorId, ?int $expectedVersion = null): void
    {
        $this->saveCalls++;
        $this->headquarters = $branch;
    }
}
