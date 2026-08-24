<?php

namespace App\Modules\Organization\Application\Branches\Queries;

final readonly class PaginatedBranches
{
    /** @param list<BranchView> $items */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
        public int $lastPage,
    ) {}

    /** @return array{data: list<array<string, bool|float|int|string|null>>, meta: array<string, int>} */
    public function toArray(): array
    {
        return [
            'data' => array_map(fn (BranchView $branch): array => $branch->toArray(), $this->items),
            'meta' => [
                'current_page' => $this->page,
                'per_page' => $this->perPage,
                'total' => $this->total,
                'last_page' => $this->lastPage,
            ],
        ];
    }
}
