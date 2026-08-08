<?php

namespace App\Modules\Organization\Application\Branches\Queries;

final readonly class BranchView
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public bool $isHeadquarters,
        public string $status,
        public int $lockVersion,
        public string $createdAt,
        public ?string $updatedAt,
    ) {}

    /** @return array<string, bool|int|string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'is_headquarters' => $this->isHeadquarters,
            'status' => $this->status,
            'lock_version' => $this->lockVersion,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
