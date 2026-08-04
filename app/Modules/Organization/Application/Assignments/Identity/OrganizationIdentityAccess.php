<?php

namespace App\Modules\Organization\Application\Assignments\Identity;

interface OrganizationIdentityAccess
{
    public function userState(string $userId): ?string;

    /** @return array{id: string, code: string, active: bool}|null */
    public function role(string $roleId): ?array;

    /** @return list<array{role_code: string, branch_id: ?string, scope_type: string}> */
    public function activeRoles(string $userId): array;
}
