<?php

namespace App\Modules\Organization\Domain\Branches\Repositories;

use App\Modules\Organization\Domain\Branches\Branch;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchCode;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;

interface BranchRepository
{
    public function find(BranchId $id): ?Branch;

    public function findByCode(BranchCode $code): ?Branch;

    public function headquarters(): ?Branch;

    public function save(Branch $branch, string $actorId, ?int $expectedVersion = null): void;
}
