<?php

namespace App\Modules\Organization\Infrastructure\Persistence\Eloquent;

use App\Modules\Organization\Domain\Branches\Branch;
use App\Modules\Organization\Domain\Branches\Exceptions\BranchVersionConflict;
use App\Modules\Organization\Domain\Branches\Repositories\BranchRepository;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchCode;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;
use InvalidArgumentException;

final readonly class EloquentBranchRepository implements BranchRepository
{
    public function __construct(private BranchMapper $mapper) {}

    public function find(BranchId $id): ?Branch
    {
        $record = BranchRecord::query()->find($id->value());

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function findByCode(BranchCode $code): ?Branch
    {
        $record = BranchRecord::query()->where('code', $code->value())->first();

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function headquarters(): ?Branch
    {
        $record = BranchRecord::query()->where('is_headquarters', true)->first();

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function save(Branch $branch, string $actorId, ?int $expectedVersion = null): void
    {
        $attributes = $this->mapper->toPersistence($branch);

        if ($expectedVersion === null) {
            BranchRecord::query()->create([
                ...$attributes,
                'created_by' => $actorId,
                'updated_by' => null,
            ]);

            return;
        }

        if ($branch->lockVersion() !== $expectedVersion + 1) {
            throw new InvalidArgumentException('La nueva versión debe ser consecutiva a la versión esperada.');
        }

        unset($attributes['id']);

        $updated = BranchRecord::query()
            ->whereKey($branch->id()->value())
            ->where('lock_version', $expectedVersion)
            ->update([
                ...$attributes,
                'updated_by' => $actorId,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new BranchVersionConflict($branch->id()->value(), $expectedVersion);
        }
    }
}
