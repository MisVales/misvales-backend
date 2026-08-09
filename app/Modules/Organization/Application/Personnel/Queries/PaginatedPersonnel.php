<?php

namespace App\Modules\Organization\Application\Personnel\Queries;

final readonly class PaginatedPersonnel
{
    /** @param list<PersonnelView> $items */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
        public int $lastPage,
    ) {}

    /** @return array{data: list<array<string, mixed>>, meta: array<string, int>} */
    public function toArray(): array
    {
        return [
            'data' => array_map(fn (PersonnelView $personnel): array => $personnel->toArray(), $this->items),
            'meta' => [
                'current_page' => $this->page,
                'per_page' => $this->perPage,
                'total' => $this->total,
                'last_page' => $this->lastPage,
            ],
        ];
    }
}
