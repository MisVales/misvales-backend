<?php

namespace App\Modules\Organization\Application\Assignments\UseCases;

use App\Modules\Organization\Application\Assignments\Repositories\AssignmentReadRepository;
use App\Modules\Organization\Domain\Assignments\Services\OrganizationScopeResolver;

final readonly class ListUserAssignments
{
    public function __construct(
        private AssignmentReadRepository $assignments,
        private OrganizationScopeResolver $scopeResolver,
    ) {}

    /** @return list<array<string, mixed>> */
    public function handle(string $userId, string $actorId, bool $includeHistory = false): array
    {
        return $this->assignments->forUser(
            $userId,
            $includeHistory,
            $this->scopeResolver->resolve($actorId),
        );
    }
}
