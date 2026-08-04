<?php

namespace App\Modules\Organization\Application\Personnel\Queries;

final readonly class PersonnelListCriteria
{
    public function __construct(
        public int $page = 1,
        public int $perPage = 15,
        public ?string $branchId = null,
        public ?string $roleId = null,
        public ?string $userState = null,
        public ?string $assignmentStatus = 'ACTIVE',
    ) {}
}
