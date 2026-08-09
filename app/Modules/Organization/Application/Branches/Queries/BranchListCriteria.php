<?php

namespace App\Modules\Organization\Application\Branches\Queries;

final readonly class BranchListCriteria
{
    public function __construct(
        public int $page = 1,
        public int $perPage = 15,
        public ?string $status = null,
        public ?string $search = null,
        public string $sort = 'created_at',
        public string $direction = 'desc',
    ) {}
}
