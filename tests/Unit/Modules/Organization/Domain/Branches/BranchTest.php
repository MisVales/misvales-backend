<?php

namespace Tests\Unit\Modules\Organization\Domain\Branches;

use App\Modules\Organization\Domain\Branches\Branch;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchCode;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchName;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchStatus;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BranchTest extends TestCase
{
    public function test_a_new_branch_starts_active_and_at_version_zero(): void
    {
        $branch = $this->newBranch();

        self::assertSame(BranchStatus::ACTIVE, $branch->status());
        self::assertSame(0, $branch->lockVersion());
        self::assertFalse($branch->isHeadquarters());
    }

    public function test_updating_details_increments_the_version_only_when_data_changes(): void
    {
        $branch = $this->newBranch();

        $branch->updateDetails(
            BranchCode::fromString('TRC-02'),
            BranchName::fromString('Sucursal Torreón Norte'),
        );

        self::assertSame('TRC-02', $branch->code()->value());
        self::assertSame('Sucursal Torreón Norte', $branch->name()->value());
        self::assertSame(1, $branch->lockVersion());

        $branch->updateDetails($branch->code(), $branch->name());

        self::assertSame(1, $branch->lockVersion());
    }

    public function test_a_regular_branch_can_be_deactivated_and_reactivated(): void
    {
        $branch = $this->newBranch();

        $branch->deactivate();
        self::assertSame(BranchStatus::INACTIVE, $branch->status());
        self::assertSame(1, $branch->lockVersion());

        $branch->activate();
        self::assertSame(BranchStatus::ACTIVE, $branch->status());
        self::assertSame(2, $branch->lockVersion());
    }

    public function test_headquarters_cannot_be_deactivated(): void
    {
        $branch = $this->newBranch(headquarters: true);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('La sucursal matriz no puede desactivarse.');

        $branch->deactivate();
    }

    public function test_reconstitution_rejects_a_negative_lock_version(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Branch::reconstitute(
            id: BranchId::fromString('019fcbec-4ba4-7721-bf39-c9729fb0bd67'),
            code: BranchCode::fromString('TRC-01'),
            name: BranchName::fromString('Sucursal Torreón Centro'),
            headquarters: false,
            status: BranchStatus::ACTIVE,
            lockVersion: -1,
        );
    }

    private function newBranch(bool $headquarters = false): Branch
    {
        return Branch::create(
            id: BranchId::fromString('019fcbec-4ba4-7721-bf39-c9729fb0bd67'),
            code: BranchCode::fromString('TRC-01'),
            name: BranchName::fromString('Sucursal Torreón Centro'),
            headquarters: $headquarters,
        );
    }
}
