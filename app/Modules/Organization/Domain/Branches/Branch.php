<?php

namespace App\Modules\Organization\Domain\Branches;

use App\Modules\Organization\Domain\Branches\Exceptions\HeadquartersBranchProtected;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchCode;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchName;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchStatus;
use InvalidArgumentException;

final class Branch
{
    private function __construct(
        private readonly BranchId $id,
        private BranchCode $code,
        private BranchName $name,
        private readonly bool $headquarters,
        private BranchStatus $status,
        private int $lockVersion,
    ) {
        if ($lockVersion < 0) {
            throw new InvalidArgumentException('La versión de la sucursal no puede ser negativa.');
        }
    }

    public static function create(
        BranchId $id,
        BranchCode $code,
        BranchName $name,
        bool $headquarters = false,
    ): self {
        return new self(
            id: $id,
            code: $code,
            name: $name,
            headquarters: $headquarters,
            status: BranchStatus::ACTIVE,
            lockVersion: 0,
        );
    }

    public static function reconstitute(
        BranchId $id,
        BranchCode $code,
        BranchName $name,
        bool $headquarters,
        BranchStatus $status,
        int $lockVersion,
    ): self {
        return new self($id, $code, $name, $headquarters, $status, $lockVersion);
    }

    public function updateDetails(BranchCode $code, BranchName $name): void
    {
        if ($this->code->equals($code) && $this->name->equals($name)) {
            return;
        }

        $this->code = $code;
        $this->name = $name;
        $this->incrementVersion();
    }

    public function activate(): void
    {
        if ($this->status === BranchStatus::ACTIVE) {
            return;
        }

        $this->status = BranchStatus::ACTIVE;
        $this->incrementVersion();
    }

    public function deactivate(): void
    {
        if ($this->headquarters) {
            throw new HeadquartersBranchProtected;
        }

        if ($this->status === BranchStatus::INACTIVE) {
            return;
        }

        $this->status = BranchStatus::INACTIVE;
        $this->incrementVersion();
    }

    public function id(): BranchId
    {
        return $this->id;
    }

    public function code(): BranchCode
    {
        return $this->code;
    }

    public function name(): BranchName
    {
        return $this->name;
    }

    public function isHeadquarters(): bool
    {
        return $this->headquarters;
    }

    public function status(): BranchStatus
    {
        return $this->status;
    }

    public function lockVersion(): int
    {
        return $this->lockVersion;
    }

    private function incrementVersion(): void
    {
        $this->lockVersion++;
    }
}
